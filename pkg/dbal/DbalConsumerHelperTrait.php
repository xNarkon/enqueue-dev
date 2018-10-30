<?php

declare(strict_types=1);

namespace Enqueue\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;

trait DbalConsumerHelperTrait
{
    abstract public function getContext(): DbalContext;

    abstract public function getConnection(): Connection;

    protected function fetchMessage(array $queues, string $deliveryId, int $redeliveryDelay): ?array
    {
        try {
            $now = time();

            $this->getConnection()->beginTransaction();

            $message = $this->getConnection()->createQueryBuilder()
                ->select('*')
                ->from($this->getContext()->getTableName())
                ->andWhere('delivery_id IS NULL')
                ->andWhere('delayed_until IS NULL OR delayed_until <= :delayedUntil')
                ->andWhere('queue IN (:queues)')
                ->addOrderBy('priority', 'desc')
                ->addOrderBy('published_at', 'asc')
                ->setParameter('delayedUntil', $now, \Doctrine\DBAL\ParameterType::INTEGER)
                ->setParameter('queues', array_values($queues), \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                ->setMaxResults(1)
                ->execute()
                ->fetch()
            ;

            if (!$message) {
                $this->getConnection()->commit();

                return null;
            }

            // mark message as delivered to consumer
            $this->getConnection()->createQueryBuilder()
                ->andWhere('id = :id')
                ->update($this->getContext()->getTableName())
                ->set('delivery_id', ':deliveryId')
                ->set('redeliver_after', ':redeliverAfter')
                ->setParameter('id', $message['id'], Type::GUID)
                ->setParameter('deliveryId', $deliveryId, Type::STRING)
                ->setParameter('redeliverAfter', $now + $redeliveryDelay, Type::BIGINT)
                ->execute();

            $this->getConnection()->commit();

            $deliveredMessage = $this->getConnection()->createQueryBuilder()
                ->select('*')
                ->from($this->getContext()->getTableName())
                ->andWhere('delivery_id = :deliveryId')
                ->setParameter('deliveryId', $deliveryId, Type::STRING)
                ->setMaxResults(1)
                ->execute()
                ->fetch();

            return $deliveredMessage ?: null;
        } catch (\Exception $e) {
            $this->getConnection()->rollBack();

            throw $e;
        }
    }
}
