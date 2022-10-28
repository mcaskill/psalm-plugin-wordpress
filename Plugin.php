<?php

namespace PsalmWordpress;

use PhpParser;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\Hook\BeforeFileAnalysisInterface;
use Psalm\Plugin\Hook\FunctionParamsProviderInterface;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Union;
use SimpleXMLElement;

class Plugin implements
	AfterEveryFunctionCallAnalysisInterface,
	BeforeFileAnalysisInterface,
	FunctionParamsProviderInterface,
	PluginEntryPointInterface {
	/**
	 * @var array<string, array{types: list<Union>}>
	 */
	public static $hooks = [];

	public function __invoke( RegistrationInterface $registration, ?SimpleXMLElement $config = null ) : void {
		require_once 'inc/HookNodeVisitor.php';
		require_once 'inc/HookNotFound.php';

		$registration->registerHooksFromClass( static::class );

		// If useDefaultStubs is not set or set to anything except false,
		// we want to load the stubs included in this plugin.
		if ( ! isset( $config->useDefaultStubs['value'] ) || (string) $config->useDefaultStubs['value'] !== 'false' ) {
			array_map( [ $registration, 'addStubFile' ], $this->getStubFiles() );
		}

		static::loadStubbedHooks();
	}

	/**
	 * Resolves a vendor-relative directory path to the absolute package directory.
	 *
	 * The plugin must run both from the source file in the repository (current working directory)
	 * as well as when required as a composer package when the current working directory may not
	 * have a vendor/ folder and the package directory is detected relative to this file.
	 *
	 * @param string $path Path of a folder, relative, inside `vendor/` (Composer).
	 *                     Must start with `vendor/` marker.
	 */
	private static function getVendorDir( string $path ) : string {
		$vendor = 'vendor/';
		$self = 'humanmade/psalm-plugin-wordpress';

		if ( 0 !== strpos( $path, $vendor ) ) {
			throw new \BadMethodCallException(
				sprintf( '$path must start with "%s", "%s" given', $vendor, $path )
			);
		}

		$cwd = getcwd();

		// Prefer path relative to current working directory (original default).
		$cwd_path = $cwd . '/' . $path;
		if ( is_dir( $cwd_path ) ) {
			return $cwd_path;
		}

		// Check running as composer package inside a vendor folder.
		$pkg_self_dir = __DIR__;
		$vendor_dir = dirname( $pkg_self_dir, 2 );
		if ( $pkg_self_dir === $vendor_dir . '/' . $self ) {
			// Likely plugin is running as composer package, let's try for the path.
			$pkg_path = substr( $path, strlen( $vendor ) );
			$vendor_path = $vendor_dir . '/' . $pkg_path;
			if ( is_dir( $vendor_path ) ) {
				return $vendor_path;
			}
		}

		// Original default behaviour.
		return $cwd_path;
	}

	/**
	 * @return string[]
	 */
	private function getStubFiles() : array {
		return [
			self::getVendorDir( 'vendor/php-stubs/wordpress-stubs' ) . '/wordpress-stubs.php',
			self::getVendorDir( 'vendor/php-stubs/wordpress-globals' ) . '/wordpress-globals.php',
			self::getVendorDir( 'vendor/php-stubs/wp-cli-stubs' ) . '/wp-cli-stubs.php',
			self::getVendorDir( 'vendor/php-stubs/wp-cli-stubs' ) . '/wp-cli-commands-stubs.php',
			self::getVendorDir( 'vendor/php-stubs/wp-cli-stubs' ) . '/wp-cli-i18n-stubs.php',
			__DIR__ . '/stubs/overrides.php',
		];
	}

	protected static function loadStubbedHooks() : void {
		if ( static::$hooks ) {
			return;
		}

		$wp_hooks_data_dir = self::getVendorDir( 'vendor/johnbillion/wp-hooks/hooks' );

		static::loadHooksFromFile( $wp_hooks_data_dir . '/actions.json' );
		static::loadHooksFromFile( $wp_hooks_data_dir . '/filters.json' );
	}

	protected static function loadHooksFromFile( string $filepath ) : void {
		/**
		 * @var list<array{
		 *     name: string,
		 *     file: string,
		 *     type: 'action'|'filter',
		 *     doc: array{
		 *         description: string,
		 *         long_description: string,
		 *         long_description_html: string,
		 *         tags: list<array{ name: string, content: string, types?: list<string>}>
		 *     }
		 * }>
		 */
		$hooks = json_decode( file_get_contents( $filepath ), true );
		$hook_map = [];
		foreach ( $hooks['hooks'] as $hook ) {
			$params = array_filter( $hook['doc']['tags'], function ( $tag ) {
				return $tag['name'] === 'param';
			} );

			$params = array_map( function ( array $param ) : array {
				if ( isset( $param['types'] ) && $param['types'] !== [ 'array' ] ) {
					return $param;
				}

				if ( substr_count( $param['content'], '{' ) !== 1 ) {
					// Unable to parse nested array style PHPDoc.
					return $param;
				}

				$found = preg_match_all( '/\@type[\s]+([^ ]+)\s+\$(\w+)/', $param['content'], $matches, PREG_SET_ORDER );
				if ( ! $found ) {
					return $param;
				}
				$array_properties = [];
				foreach ( $matches as $match ) {
					$array_properties[] = $match[2] . ': ' . $match[1];
				}
				$array_string = 'array{ ' . implode( ', ', $array_properties ) . ' }';
				$param['types'] = [ $array_string ];
				return $param;
			}, $params );

			$types = array_column( $params, 'types' );

			$types = array_map( function ( $type ) : string {
				return implode( '|', $type );
			}, $types );

			// Remove empty elements which can happen with invalid PHPDoc.
			// Must be done before parseString to avoid notice there.
			$types = array_filter( $types );

			static::registerHook( $hook['name'], array_map( [ Type::class, 'parseString' ], $types ), $hook['type'] );
		}
	}

	public static function beforeAnalyzeFile(
		StatementsSource $statements_source,
		Context $file_context,
		FileStorage $file_storage,
		Codebase $codebase
	) : void {
		$statements = $codebase->getStatementsForFile( $statements_source->getFilePath() );
		$traverser = new PhpParser\NodeTraverser;
		$hook_visitor = new HookNodeVisitor();
		$traverser->addVisitor( $hook_visitor );
		try {
			$traverser->traverse( $statements );
		} catch ( \Exception $e ) {
			// Do nothing.
		}

		foreach ( $hook_visitor->hooks as $hook_name => $hook ) {
			static::registerHook( $hook_name, $hook['types'], $hook['hook_type'] );
		}
	}

	public static function afterEveryFunctionCallAnalysis(
		FuncCall $expr,
		string $function_id,
		Context $context,
		StatementsSource $statements_source,
		Codebase $codebase
	) : void {
		$apply_filter_functions = [
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
		];

		$do_action_functions = [
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		];

		if ( in_array( $function_id, $apply_filter_functions, true ) ) {
			$hook_type = 'filter';
		} elseif ( in_array( $function_id, $do_action_functions, true ) ) {
			$hook_type = 'action';
		} else {
			return;
		}

		if ( ! $expr->args[0]->value instanceof String_ ) {
			return;
		}

		$name = $expr->args[0]->value->value;
		// Check if this hook is already documented.
		if ( isset( static::$hooks[ $name ] ) ) {
			return;
		}

		$types = array_map( function ( Arg $arg ) use ( $statements_source ) {
			$type = $statements_source->getNodeTypeProvider()->getType( $arg->value );
			if ( ! $type ) {
				$type = Type::parseString( 'mixed' );
			} else {
				$sub_types = array_values( $type->getAtomicTypes() );
				$sub_types = array_map( function ( Atomic $type ) : Atomic {
					if ( $type instanceof Atomic\TTrue || $type instanceof Atomic\TFalse ) {
						return new Atomic\TBool;
					} elseif ( $type instanceof Atomic\TLiteralString ) {
						return new Atomic\TString;
					} elseif ( $type instanceof Atomic\TLiteralInt ) {
						return new Atomic\TInt;
					} elseif ( $type instanceof Atomic\TLiteralFloat ) {
						return new Atomic\TFloat;
					}

					return $type;
				}, $sub_types );
				$type = new Union( $sub_types );
			}

			return $type;
		}, array_slice( $expr->args, 1 ) );

		static::registerHook( $name, $types, $hook_type );
	}

	public static function getFunctionIds() : array {
		return [
			'add_action',
			'add_filter',
		];
	}

	/**
	 * @param  list<PhpParser\Node\Arg>    $call_args
	 *
	 * @return ?array<int, \Psalm\Storage\FunctionLikeParameter>
	 */
	public static function getFunctionParams(
		StatementsSource $statements_source,
		string $function_id,
		array $call_args,
		Context $context = null,
		CodeLocation $code_location = null
	) : ?array {
		static::loadStubbedHooks();

		// Currently we only support detecting the hook name if it's a string.
		if ( ! $call_args[0]->value instanceof String_ ) {
			return null;
		}

		$hook_name = $call_args[0]->value->value;
		$hook = static::$hooks[ $hook_name ] ?? null;
		$is_action = ( $function_id === 'add_action' );

		if ( ! $hook ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook ' . $hook_name . ' not found',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}
			return [];
		}

		// "action_reference" for "do_action_ref_array".
		if ( $is_action && ! in_array( $hook['hook_type'], [ 'action', 'action_reference' ], true ) ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook ' . $hook_name . ' is a filter not an action',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}
			return [];
		}

		// "filter_reference" for "apply_filters_ref_array".
		if ( ! $is_action && ! in_array( $hook['hook_type'], [ 'filter', 'filter_reference' ], true ) ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook ' . $hook_name . ' is an action not a filter',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}
			return [];
		}

		// Check how many args the filter is registered with.
		/** @var int */
		$num_args = $call_args[3]->value->value ?? 1;
		// Limit the required type params on the hook to match the registered number.
		$hook_types = array_slice( $hook['types'], 0, $num_args );

		$hook_params = array_map( function ( Union $type ) : FunctionLikeParameter {
			return new FunctionLikeParameter( 'param', false, $type, null, null, false );
		}, $hook_types );

		// Actions must return null/void. Filters must return the same type as the first param.
		if ( $is_action ) {
			$return_type = Type::parseString( 'void|null' );
		} elseif ( isset( $hook['types'][0] ) ) {
			$return_type = $hook['types'][0];
		} else {
			// Unknown due to lack of PHPDoc - but a filter must always
			// return something - mixed is the most generic case.
			$return_type = Type::parseString( 'mixed' );
		}

		$return = [
			new FunctionLikeParameter( 'Hook', false, Type::parseString( 'string' ), null, null, false ),
			new FunctionLikeParameter( 'Callback', false, new Union( [
				new Atomic\TCallable(
					'callable',
					$hook_params,
					$return_type
				),
			] ), null, null, false ),
			new FunctionLikeParameter( 'Priority', false, Type::parseString( 'int|null' ) ),
			new FunctionLikeParameter( 'Args', false, Type::parseString( 'int|null' ) ),
		];
		return $return;
	}

	/**
	 * @param list<Union> $types
	 */
	public static function registerHook( string $hook, array $types, string $hook_type = 'filter' ) : void {
		// Remove empty elements which can happen with invalid PHPDoc.
		$types = array_filter( $types );

		// Do not assign empty types if we already have this hook registered.
		if ( isset( static::$hooks[ $hook ] ) && empty( $types ) ) {
			return;
		}

		// If this hook is registered already.
		if ( isset( static::$hooks[ $hook ] ) ) {
			// If we have more types than already registered we overwrite existing ones,
			// but keep the additional ones (array_merge would combine them which is wrong).
			$types = $types + static::$hooks[ $hook ]['types'];
		}

		static::$hooks[ $hook ] = [
			'hook_type' => $hook_type,
			'types'     => $types,
		];
	}
}
