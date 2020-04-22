<?php
declare(strict_types=1);
/**
 * APISubmitter.php
 * Copyright (c) 2020 james@firefly-iii.org
 *
 * This file is part of the Firefly III CSV importer
 * (https://github.com/firefly-iii/csv-importer).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Services\Import\Routine;

use App\Exceptions\ImportException;
use App\Services\Import\Support\ProgressInformation;
use GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException;
use GrumpyDictator\FFIIIApiSupport\Model\Transaction;
use GrumpyDictator\FFIIIApiSupport\Model\TransactionGroup;
use GrumpyDictator\FFIIIApiSupport\Request\PostTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Response\PostTransactionResponse;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;
use Log;

/**
 * Class APISubmitter
 */
class APISubmitter
{
    use ProgressInformation;

    /**
     * @param array $lines
     */
    public function processTransactions(array $lines): void
    {
        $count = count($lines);
        Log::info(sprintf('Going to submit %d transactions to your Firefly III instance.', $count));
        /**
         * @var int   $index
         * @var array $line
         */
        foreach ($lines as $index => $line) {
            $this->processTransaction($index, $line);
        }
        Log::info(sprintf('Done submitting %d transactions to your Firefly III instance.', $count));
    }

    /**
     * @param int              $lineIndex
     * @param array            $line
     * @param TransactionGroup $group
     */
    private function compareArrays(int $lineIndex, array $line, TransactionGroup $group): void
    {
        // some fields may not have survived. Be sure to warn the user about this.
        /** @var Transaction $transaction */
        foreach ($group->transactions as $index => $transaction) {
            // compare currency ID
            if (null !== $line['transactions'][$index]['currency_id']
                && (int)$line['transactions'][$index]['currency_id'] !== (int)$transaction->currencyId
            ) {
                $this->addWarning(
                    $lineIndex,
                    sprintf(
                        'Line #%d may have had its currency changed (from ID #%d to ID #%d). This happens because the associated asset account overrules the currency of the transaction.',
                        $lineIndex, $line['transactions'][$index]['currency_id'], (int)$transaction->currencyId
                    )
                );
            }
            // compare currency code:
            if (null !== $line['transactions'][$index]['currency_code']
                && $line['transactions'][$index]['currency_code'] !== $transaction->currencyCode
            ) {
                $this->addWarning(
                    $lineIndex,
                    sprintf(
                        'Line #%d may have had its currency changed (from "%s" to "%s"). This happens because the associated asset account overrules the currency of the transaction.',
                        $lineIndex, $line['transactions'][$index]['currency_code'], $transaction->currencyCode
                    )
                );
            }

        }
    }

    /**
     * @param int   $index
     * @param array $line
     */
    private function processTransaction(int $index, array $line): void
    {
        $uri     = (string) config('csv_importer.uri');
        $token   = (string) config('csv_importer.access_token');
        $request = new PostTransactionRequest($uri, $token);
        Log::debug('Submitting to Firefly III:', $line);
        $request->setBody($line);

        try {
            $response = $request->post();
        } catch (ApiHttpException $e) {
            Log::error($e->getMessage());
            Log::error($e->getTraceAsString());
            $message = sprintf(sprintf('Submission HTTP error: %s', $e->getMessage()));
            $this->addError($index, $message);
            return;
        }

        if ($response instanceof ValidationErrorResponse) {
            foreach ($response->errors->messages() as $key => $errors) {
                Log::error(sprintf('Submission error: %d', $key), $errors);
                foreach ($errors as $error) {
                    $msg = sprintf('%s: %s (original value: "%s")', $key, $error, $this->getOriginalValue($key, $line));
                    // plus 1 to keep the count.
                    $this->addError($index, $msg);
                    Log::error($msg);
                }
            }
            return;
        }

        if ($response instanceof PostTransactionResponse) {
            /** @var TransactionGroup $group */
            $group = $response->getTransactionGroup();
            /** @var Transaction $transaction */
            $transaction = $group->transactions[0];
            $message     = sprintf(
                'Created %s <a target="_blank" href="%s">#%d "%s"</a> (%s %s)',
                $transaction->type,
                sprintf('%s/transactions/show/%d', env('FIREFLY_III_URI'), $group->id),
                $group->id,
                e($transaction->description),
                $transaction->currencyCode,
                round($transaction->amount, $transaction->currencyDecimalPlaces)
            );
            // plus 1 to keep the count.
            $this->addMessage($index, $message);
            $this->compareArrays($index, $line, $group);
            Log::info($message);
        }
    }

    /**
     * @param string $key
     * @param array  $transaction
     *
     * @return string
     */
    private function getOriginalValue(string $key, array $transaction): string
    {
        $parts = explode('.', $key);
        if(1 === count($parts)) {
            return $transaction[$key] ?? '(not found)';
        }
        if (3 !== count($parts)) {
            return '(unknown)';
        }
        $index = (int)$parts[1];

        return (string)($transaction['transactions'][$index][$parts[2]] ?? '(not found)');
    }

}
