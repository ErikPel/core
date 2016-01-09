<?php
/**
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Lukas Reschke <lukas@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Session;

use OCP\ISession;
use OCP\Security\ICrypto;

/**
 * Class CryptoSessionData
 *
 * @package OC\Session
 */
class CryptoSessionData implements \ArrayAccess, ISession {
	/** @var ISession */
	protected $session;
	/** @var \OCP\Security\ICrypto */
	protected $crypto;
	/** @var string */
	protected $passphrase;
	/** @var array */
	protected $sessionValues;
	/** @var bool */
	protected $isModified = false;
	CONST encryptedSessionName = 'encrypted_session_data';

	/**
	 * @param ISession $session
	 * @param ICrypto $crypto
	 * @param string $passphrase
	 */
	public function __construct(ISession $session,
								ICrypto $crypto,
								$passphrase) {
		$this->crypto = $crypto;
		$this->session = $session;
		$this->passphrase = $passphrase;
		$this->initializeSession();
	}

	/**
	 * Close session if class gets destructed
	 */
	public function __destruct() {
		$this->close();
	}

	protected function initializeSession() {
		$encryptedSessionData = $this->session->get(self::encryptedSessionName);
		try {
			$this->sessionValues = json_decode(
				$this->crypto->decrypt($encryptedSessionData, $this->passphrase),
				true
			);
		} catch (\Exception $e) {
			$this->sessionValues = [];
		}
	}

	/**
	 * Set a value in the session
	 *
	 * @param string $key
	 * @param mixed $value
	 */
	public function set($key, $value) {
		if($key === 'LAST_ACTIVITY' && $this->exists('user_id')) {
			$lastDBUpdate = $this->get('LAST_DB_UPDATE');
			if (time() - $lastDBUpdate > 5) {
				$this->set('LAST_DB_UPDATE', time());
				\OCP\Util::writeLog('session_logging', 'update last activity - sessionID: ' . session_id() . ' last activity: ' . $value, \OCP\Util::ERROR);
			}
		}

		$this->sessionValues[$key] = $value;
		$this->isModified = true;

		if($key === 'user_id' && $value !== '') {
			$this->set('LAST_DB_UPDATE', time());

			$request = \OC::$server->getRequest();
			$userAgent = $request->server['HTTP_USER_AGENT'];
			$remoteAddr = $request->getRemoteAddress();

			$createdAt = time();
			\OCP\Util::writeLog('session_logging', 'add new session -  user ID: ' . $value . ' sessionID: ' . session_id() . ' created: ' . $createdAt . ' last activity: ' . $this->get('LAST_ACTIVITY') . ' user agent: ' . $userAgent . ' IP: ' . $remoteAddr, \OCP\Util::ERROR);
		}
	}

	/**
	 * Get a value from the session
	 *
	 * @param string $key
	 * @return string|null Either the value or null
	 */
	public function get($key) {
		if(isset($this->sessionValues[$key])) {
			return $this->sessionValues[$key];
		}

		return null;
	}

	/**
	 * Check if a named key exists in the session
	 *
	 * @param string $key
	 * @return bool
	 */
	public function exists($key) {
		return isset($this->sessionValues[$key]);
	}

	/**
	 * Remove a $key/$value pair from the session
	 *
	 * @param string $key
	 */
	public function remove($key) {
		$this->isModified = true;
		unset($this->sessionValues[$key]);
		$this->session->remove(self::encryptedSessionName);
	}

	/**
	 * Reset and recreate the session
	 */
	public function clear() {
		if($this->exists('LAST_DB_UPDATE')) {
			\OCP\Util::writeLog('session_logging', 'clear session (clear) - sessionID: ' . session_id(), \OCP\Util::ERROR);
		}

		$this->sessionValues = [];
		$this->isModified = true;
		$this->session->clear();
	}

	/**
	 * Wrapper around session_regenerate_id
	 *
	 * @param bool $deleteOldSession Whether to delete the old associated session file or not.
	 * @return void
	 */
	public function regenerateId($deleteOldSession = true) {
		$userId = $this->get('user_id');
		$isValidUserId = !is_null($userId) && $userId !== '';
		if($deleteOldSession && $isValidUserId) {
			\OCP\Util::writeLog('session_logging', 'clear session (regenerateID) - sessionID: ' . session_id(), \OCP\Util::ERROR);
		}

		$this->session->regenerateId($deleteOldSession);

		if($isValidUserId) {
			$this->set('LAST_DB_UPDATE', time());

			$request = \OC::$server->getRequest();
			$userAgent = $request->server['HTTP_USER_AGENT'];
			$remoteAddr = $request->getRemoteAddress();

			$createdAt = time();
			\OCP\Util::writeLog('session_logging', 'add new session (regenerate) -  user ID: ' . $userId . ' sessionID: ' . session_id() . ' created: ' . $createdAt . ' last activity: ' . $this->get('LAST_ACTIVITY') . ' user agent: ' . $userAgent . ' IP: ' . $remoteAddr, \OCP\Util::ERROR);
		}
	}

	/**
	 * Close the session and release the lock, also writes all changed data in batch
	 */
	public function close() {
		if($this->isModified) {
			$encryptedValue = $this->crypto->encrypt(json_encode($this->sessionValues), $this->passphrase);
			$this->session->set(self::encryptedSessionName, $encryptedValue);
			$this->isModified = false;
		}
		$this->session->close();
	}

	/**
	 * @param mixed $offset
	 * @return bool
	 */
	public function offsetExists($offset) {
		return $this->exists($offset);
	}

	/**
	 * @param mixed $offset
	 * @return mixed
	 */
	public function offsetGet($offset) {
		return $this->get($offset);
	}

	/**
	 * @param mixed $offset
	 * @param mixed $value
	 */
	public function offsetSet($offset, $value) {
		$this->set($offset, $value);
	}

	/**
	 * @param mixed $offset
	 */
	public function offsetUnset($offset) {
		$this->remove($offset);
	}
}
