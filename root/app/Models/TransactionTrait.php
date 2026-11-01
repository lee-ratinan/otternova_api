<?php

namespace App\Models;

use Throwable;

/**
 * Transactions for models whose writes go to log_activity in the same transaction (see App\Libraries\ActivityLog)
 */
trait TransactionTrait
{
    /**
     * Run $work in a transaction: committed when it returns, rolled back when it throws
     * (or returns false, for a refusal found inside the transaction)
     * @param callable $work
     * @return mixed What $work returned
     * @throws Throwable
     */
    protected function inTransaction(callable $work): mixed
    {
        // inside a transaction a failed query only sets a flag by default; make it roll back and throw instead
        $this->db->resetTransStatus()->transException(true)->transBegin();
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->db->transRollback(); // a failed query has rolled back already; this is a no-op then

            throw $e;
        } finally {
            $this->db->transException(false);
        }
        $result === false ? $this->db->transRollback() : $this->db->transCommit();

        return $result;
    }
}
