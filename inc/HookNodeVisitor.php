<?php

namespace PsalmWordpress;

use phpDocumentor;
use PhpParser;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Return_;
use Psalm\Type;
use Psalm\Type\Union;

class HookNodeVisitor extends PhpParser\NodeVisitorAbstract {
	/** @var ?PhpParser\Comment\Doc */
	protected $last_doc = null;

	/** @var array<string, list<Union>> */
	public $hooks = [];

	public function enterNode( PhpParser\Node $orig_node ) {
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

		// "return apply_filters" will assign the PHPDoc to the return instead
		// of the apply_filters, so we need to store it
		// "$var = apply_filters" directly after a function declaration
		// "echo apply_filters" cannot do this for all cases,
		// as often it will assign completely wrong stuff otherwise.
		if (
			$orig_node->getDocComment() && (
				$orig_node instanceof FuncCall ||
				$orig_node instanceof Return_ ||
				$orig_node instanceof Variable ||
				$orig_node instanceof Echo_
			)
		) {
			$this->last_doc = $orig_node->getDocComment();
		} elseif ( isset( $this->last_doc ) && ! $orig_node instanceof FuncCall ) {
			// If it's set already and this is not a FuncCall, reset it to null,
			// since there's something else and it would be used incorrectly.
			$this->last_doc = null;
		}

		if ( $this->last_doc && $orig_node instanceof FuncCall && $orig_node->name instanceof Name ) {
			if ( in_array( (string) $orig_node->name, $apply_filter_functions, true ) ) {
				$hook_type = 'filter';
			} elseif ( in_array( (string) $orig_node->name, $do_action_functions, true ) ) {
				$hook_type = 'action';
			} else {
				return null;
			}

			if ( ! $orig_node->args[0]->value instanceof String_ ) {
				$this->last_doc = null;
				return null;
			}

			$hook_name = $orig_node->args[0]->value->value;

			$doc_comment = $this->last_doc->getText();

			$doc_factory = phpDocumentor\Reflection\DocBlockFactory::createInstance();
			try {
				$doc_block = $doc_factory->create( $doc_comment );
			} catch ( \RuntimeException $e ) {
				return null;
			} catch ( \InvalidArgumentException $e ) {
				return null;
			}

			/** @var phpDocumentor\Reflection\DocBlock\Tags\Param[] */
			$params = $doc_block->getTagsByName( 'param' );

			$types = [];
			foreach ( $params as $param ) {
				// Might be instanceof phpDocumentor\Reflection\DocBlock\Tags\invalidTag
				// if the param is invalid.
				if ( ! ( $param instanceof phpDocumentor\Reflection\DocBlock\Tags\Param ) ) {
					// Set to mixed - if we skip it, it will mess up all subsequent args.
					$types[] = 'mixed';
					continue;
				}
				$param_type = $param->getType();
				if ( is_null( $param_type ) ) {
					// Set to mixed - if we skip it, it will mess up all subsequent args.
					$types[] = 'mixed';
					continue;
				}

				$types[] = $param_type->__toString();
			}

			if ( empty( $types ) ) {
				return null;
			}

			$types = array_map( [ Type::class, 'parseString' ], $types );

			$this->hooks[ $hook_name ] = [
				'hook_type' => $hook_type,
				'types'     => $types,
			];
			$this->last_doc = null;
		}

		return null;
	}
}
