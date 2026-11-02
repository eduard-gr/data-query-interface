<?php

declare(strict_types=1);

namespace Trackpoint\DataQueryInterface\Planer;

use Trackpoint\DataQueryInterface\InterfaceFeature;
use Trackpoint\DataQueryInterface\Resolver\InterfaceResolver;
use Trackpoint\DataQueryInterface\Statement\StatementInterface;
use Trackpoint\DataQueryInterface\Statement\InsertStatement;
use Trackpoint\DataQueryInterface\Statement\InsertJoin;
use Trackpoint\DataQueryInterface\Metadata\AttributeMetadata;

use Psr\Log\LoggerInterface;

use Ds\Deque;
use Ds\Map;
use Ds\Queue;
use Exception;

class InsertPlaner implements PlanerInterface
{

	private InterfaceResolver $resolver;
	private LoggerInterface $logger;

	public function __construct(InterfaceResolver $resolver, LoggerInterface $logger)
	{
		$this->resolver = $resolver;
		$this->logger = $logger;
	}

	public function getExecutionPlan(InterfaceFeature $feature): StatementInterface
	{
		$priority = new Deque([$feature]);
		$processing = new Queue([$feature]);

		/**
		 * Для каждой фичи, стоящей на стороне FOREIGN, запоминаем имя ключа,
		 * который она должна получить из уже вставленного родителя.
		 * Это и есть relation key для InsertJoin.
		 */
		$joins = new Map();

		while ($processing->isEmpty() == false) {
			$feature = $processing->pop();

			$relations = $feature->getRelations();
			foreach ($relations as $relation) {

				if ($relation->isVisited() == true) {
					continue;
				}

				$relation->visited();

				$related = $relation->getRelatedFeature();

				$processing->push($related);

				$join = $relation->getJoin();

				switch ($feature->getMetadata()->getAttribute($join)->getConstrain()) {
					case AttributeMetadata::PRIMARY:
						$feature->getReturning()->add($join);
						break;
					case AttributeMetadata::FOREIGN:
						$feature->getData()->put($join, null);
						$joins->put($feature, $join);
						break;
				}

				switch ($related->getMetadata()->getAttribute($join)->getConstrain()) {
					case AttributeMetadata::PRIMARY:
						$related->getReturning()->add($join);
						$priority->unshift($related);
						break;
					case AttributeMetadata::FOREIGN:
						$related->getData()->put($join, null);
						$joins->put($related, $join);
						$priority->push($related);
						break;
				}
			}
		}

		$head = new InsertStatement($this->resolver, $priority->shift());

		while ($priority->isEmpty() == false) {
			$related = $priority->shift();

			$join = $joins->get($related, null);
			if ($join === null) {
				throw new Exception(sprintf(
					'Interface "%s" has no foreign key to join inserted data on',
					$related->getName()
				));
			}

			$head = new InsertJoin(
				new InsertStatement(
					$this->resolver,
					$related
				),
				$head,
				$join
			);
		}

		return $head;
	}
}
