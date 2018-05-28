<?php

declare(strict_types = 1);

/**
 * @copyright 2018 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2018 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @license GNU AGPL version 3 or any later version
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Authentication\TwoFactorAuth\Db;

use OCP\IDBConnection;

/**
 * Data access object to query and assign (provider_id, uid, enabled) tuples of
 * 2FA providers
 */
class ProviderUserAssignmentDao {

	const TABLE_NAME = 'twofactor_providers';

	/** @var IDBConnection */
	private $conn;

	public function __construct(IDBConnection $dbConn) {
		$this->conn = $dbConn;
	}

	/**
	 * Get all assigned provider IDs for the given user ID
	 *
	 * @return string[] where the array key is the provider ID (string) and the
	 *                  value is the enabled state (bool)
	 */
	public function getState(string $uid): array {
		$qb = $this->conn->getQueryBuilder();

		$query = $qb->select('provider_id', 'enabled')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$result = $query->execute();
		$providers = [];
		foreach ($result->fetchAll() as $row) {
			$providers[$row['provider_id']] = $row['enabled'] === 1;
		}
		$result->closeCursor();

		return $providers;
	}

	/**
	 * Persist a new/updated (provider_id, uid, enabled) tuple
	 */
	public function persist(string $providerId, string $uid, bool $enabled) {
		$qb = $this->conn->getQueryBuilder();

		// TODO: concurrency? What if (providerId, uid) private key is inserted
		//       twice at the same time?
		$query = $qb->insert(self::TABLE_NAME)->values([
			'provider_id' => $qb->createNamedParameter($providerId),
			'uid' => $qb->createNamedParameter($uid),
			'enabled' => $qb->createNamedParameter($enabled),
		]);

		$result = $query->execute();
	}

}
