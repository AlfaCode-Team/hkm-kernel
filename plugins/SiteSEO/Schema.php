<?php
namespace Plugins\SiteSEO;

use Plugins\SiteSEO\Interfaces\SchemaInterface;

/**
 * @package Plugins\SiteSEO
 * @since v2.0
 * @see https://git.io/phpseo
 * @license MIT
 * @copyright 2019-present Mohamed Elabhja
 */
class Schema implements SchemaInterface
{

	protected $things = [];

	/**
	 * @param string               $type
	 * @param array                $data
	 * @param SchemaInterface|null $parent
	 * @param SchemaInterface|null $root
	 */
	public function __construct(SchemaInterface ...$things)
	{
		$this->things = $things;
	}


	/**
	 * Add schema item to the graph.
	 *
	 * @param SchemaInterface $thing
	 */
	public function add(SchemaInterface $thing): SchemaInterface
	{
		$this->things[] = $thing;
		return $this;
	}

	/**
	 * Get data as array
	 *
	 * @return array
	 */
	public function jsonSerialize(): array
	{
		// A single node renders flat ({ "@context", "@type", … }); multiple nodes
		// render as a connected "@graph" — the shape Google prefers for rich
		// results, where nodes cross-reference each other by "@id". (Previously
		// only things[0] was serialized, so add() silently dropped every extra
		// node and no @graph was ever produced.)
		if (count($this->things) <= 1) {
			$json = [
				'@context' => 'https://schema.org',
				...($this->things[0] ?? new \Plugins\SiteSEO\Schema\Thing('Thing'))->jsonSerialize(),
			];

			ksort($json);

			return $json;
		}

		return [
			'@context' => 'https://schema.org',
			'@graph'   => array_map(
				static fn(SchemaInterface $thing): array => $thing->jsonSerialize(),
				$this->things,
			),
		];
	}


	/**
	 * Serialize root schema
	 *
	 * @return string
	 */
	public function __toString(): string
	{
		// JSON_HEX_TAG: with slashes unescaped, a "</script>" inside any
		// user-sourced string value would otherwise close the JSON-LD block
		// and execute markup (stored XSS on public pages).
		return '<script type="application/ld+json">'. json_encode($this->jsonSerialize(), \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) .'</script>';
	}

}