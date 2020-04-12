<?php
declare(strict_types=1);
/**
 * OpposingAccounts.php
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

namespace App\Services\CSV\Mapper;

use GrumpyDictator\FFIIIApiSupport\Model\Account;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;
use GrumpyDictator\FFIIIApiSupport\Response\GetAccountsResponse;
use Log;

/**
 * Class OpposingAccounts
 */
class OpposingAccounts implements MapperInterface
{

    /**
     * Get map of objects.
     *
     * @throws \GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException
     * @return array
     */
    public function getMap(): array
    {
        Log::debug('Now in OpposingAccounts::getMap()');
        $result = [];
        $uri    = (string) config('csv_importer.uri');
        $token  = (string) config('csv_importer.access_token');
        // get list of asset accounts:
        $request = new GetAccountsRequest($uri, $token);
        $request->setType(GetAccountsRequest::ALL);
        $response = $request->get();

        Log::debug(sprintf('Response class is %s', get_class($response)));
        if ($response instanceof GetAccountsResponse) {
            Log::debug(sprintf('Count of response is %d', $response->count()));
            /** @var Account $account */
            foreach ($response as $account) {
                $name = $account->name;
                if (null !== $account->iban) {
                    $name = sprintf('%s (%s)', $account->name, $account->iban);
                }
                Log::debug(sprintf('Found name %s', $name));
                // add optgroup to result:
                $group                        = trans(sprintf('import.account_types_%s', $account->type));
                $result[$group]               = $result[$group] ?? [];
                $result[$group][$account->id] = $name;
            }
        }
        Log::debug('Final result is ', $result);

        return $result;
    }
}

