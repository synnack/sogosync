<?php
/***********************************************
* File      :   sqlstatemachine.php
* Project   :   Z-Push
* Descr     :   This class handles state requests;
*               Each Import/Export mechanism can
*               store its own state information,
*               which is stored through the
*               state machine.
*
* Created   :   14.02.2013
*
* Copyright 2013 Wilco Baan Hofman, NIKHEF
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

class SqlStateMachine implements IStateMachine {
    private $dbh;
    /**
     * Constructor
     *
     * Performs some basic checks and initilizes the state directory
     *
     * @access public
     * @throws FatalMisconfigurationException
     */
    public function SqlStateMachine() {
        if (!defined('STATE_SQL_DSN'))
            throw new FatalMisconfigurationException("No DSN for the state SQL database available.");
        if (!defined('STATE_SQL_USER'))
            throw new FatalMisconfigurationException("No username for the state SQL database available.");
        if (!defined('STATE_SQL_PASS'))
            throw new FatalMisconfigurationException("No password for the state SQL database available.");

        try {
            $this->dbh = new PDO(STATE_SQL_DSN, STATE_SQL_USER, STATE_SQL_PASS);
        } catch (PDOException $e) {
            throw new FatalMisconfigurationException("Could not connect to SQL database: ". STATE_SQL_DSN);
        }
    }

    /**
     * Gets a hash value indicating the latest dataset of the named
     * state with a specified key and counter.
     * If the state is changed between two calls of this method
     * the returned hash should be different
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     *
     * @access public
     * @return string
     * @throws StateNotFoundException, StateInvalidException
     */
    public function GetStateHash($devid, $type, $key = false, $counter = false) {
        try {
            $sth = $this->dbh->prepare("SELECT lastsync ".
                                       "FROM sogosync_statemachine ".
                                       "WHERE deviceid=:deviceid ".
                                       "AND statetype=:statetype ".
                                       "AND uuid=:uuid ".
                                       "AND counter=:counter");
            $sth->execute(array(
                                ":deviceid" => $devid, 
                                ":statetype" => $type, 
                                ":uuid" => $key,
                                ":counter" => $counter === false ? -1 : $counter
            ));

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record)
                throw new StateNotFoundException("FileStateMachine->GetStateHash(): Could not locate state");

            return sha1($record['lastsync']);
        }
        catch(PDOException $e) {
            throw new StateNotFoundException("SqlStateMachine->GetStateHash(): Could not locate state");
        }
    }

    /**
     * Gets a state for a specified key and counter.
     * This method sould call IStateMachine->CleanStates()
     * to remove older states (same key, previous counters)
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     * @param string    $cleanstates        (opt)
     *
     * @access public
     * @return mixed
     * @throws StateNotFoundException, StateInvalidException
     */
    public function GetState($devid, $type, $key = false, $counter = false, $cleanstates = true) {
        if ($counter && $cleanstates)
            $this->CleanStates($devid, $type, $key, $counter);

        try {
            $sth = $this->dbh->prepare("SELECT content ".
                                       "FROM sogosync_statemachine ".
                                       "WHERE deviceid=:deviceid ".
                                       "AND statetype=:statetype ".
                                       "AND uuid=:uuid ".
                                       "AND counter=:counter");
            $sth->execute(array(
                                ":deviceid" => $devid, 
                                ":statetype" => $type, 
                                ":uuid" => $key,
                                ":counter" => $counter === false ? -1 : $counter
            ));

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record)
                throw new StateNotFoundException(sprintf("FileStateMachine->GetStateHash(): Could not locate state '%s'",$filename));
            return unserialize($record['content']);
        }
        catch (PDOException $e) {
            // throw an exception on all other states, but not FAILSAVE as it's most of the times not there by default
            if ($type !== IStateMachine::FAILSAVE)
               throw new StateNotFoundException(sprintf("SqlStateMachine->GetState(): Could not locate state '%s'",$filename));
        }
    }

    /**
     * Writes the state to for a key and counter
     *
     * @param mixed     $state
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param int       $counter            (opt)
     *
     * @access public
     * @return boolean
     * @throws StateInvalidException
     */
    public function SetState($state, $devid, $type, $key = false, $counter = false) {
        $state = serialize($state);

        $pdo_params = array(
            ":content" => $state,
            ":deviceid" => $devid, 
            ":statetype" => $type, 
            ":uuid" => $key,
            ":counter" => $counter === false ? -1 : $counter
        );
        try {
            $sth = $this->dbh->prepare("UPDATE sogosync_statemachine ".
                                       "SET content=:content, ".
                                       "lastsync=NOW() ".
                                       "WHERE deviceid=:deviceid ".
                                       "AND statetype=:statetype ".
                                       "AND uuid=:uuid ".
                                       "AND counter=:counter");
            $sth->execute($pdo_params);

            if ($sth->rowCount() == 0) {
                $sth = $this->dbh->prepare("INSERT INTO sogosync_statemachine ".
                                           "(deviceid, statetype, uuid, counter, content, lastsync) ".
                                           "VALUES (:deviceid, :statetype, :uuid, :counter, :content, NOW())");
                $sth->execute($pdo_params);
            }

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->SetState() written to SQL"));
            return strlen($state);
        }
        catch (PDOException $e) {
            throw new FatalMisconfigurationException(sprintf("SqlStateMachine->SetState(): Could not write state '%s'",$filename));
        }
    }

    /**
     * Cleans up all older states
     * If called with a $counter, all states previous state counter can be removed
     * If called without $counter, all keys (independently from the counter) can be removed
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key
     * @param string    $counter            (opt)
     *
     * @access public
     * @return
     * @throws StateInvalidException
     */
    public function CleanStates($devid, $type, $key, $counter = false) {
        $pdo_params = array(
            ":deviceid" => $devid, 
            ":statetype" => $type, 
            ":uuid" => $key,
            ":counter" => $counter === false ? -1 : $counter
        );
        try {
            if ($counter) {
                $sth = $this->dbh->prepare("DELETE FROM sogosync_statemachine ".
                                           "WHERE deviceid=:deviceid ".
                                           "AND statetype=:statetype ".
                                           "AND uuid=:uuid ".
                                           "AND counter < :counter");
            } else {
                $sth = $this->dbh->prepare("DELETE FROM sogosync_statemachine ".
                                           "WHERE deviceid=:deviceid ".
                                           "AND statetype=:statetype ".
                                           "AND uuid=:uuid ".
                                           "AND counter=:counter");
            }
            $sth->execute($pdo_params);
        }
        catch (PDOException $e) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->CleanStates(): cleanup failed!"));
        }
    }

    /**
     * Links a user to a device
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return
     */
    public function LinkUserDevice($username, $devid) {
        $pdo_params = array(
            ":username" => $username,
            ":deviceid" => $devid
        );
        try {
            $sth = $this->dbh->prepare("SELECT id FROM sogosync_userdevices ".
                                       "WHERE username=:username ".
                                       "AND deviceid=:deviceid ");
            $sth->execute($pdo_params);

            if ($sth->rowCount() == 0) {
                $sth = $this->dbh->prepare("INSERT INTO sogosync_userdevices ".
                                           "(username, deviceid) VALUES ".
                                           "(:username,:deviceid)");
                $sth->execute($pdo_params);
                ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->LinkUserDevice(): linked device to user");
            } else {
                ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->LinkUserDevice(): nothing changed");
            }
        }
        catch (PDOException $e) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->LinkUserDevice(): linking failed!"));
        }
    }

   /**
     * Unlinks a device from a user
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return
     */
    public function UnLinkUserDevice($username, $devid) {
        $pdo_params = array(
            ":username" => $username,
            ":deviceid" => $devid
        );
        try {
            $sth = $this->dbh->prepare("DELETE FROM sogosync_userdevices ".
                                       "WHERE username=:username ".
                                       "AND deviceid=:deviceid ");
            $sth->execute($pdo_params);

            if ($sth->rowCount() == 0)
                ZLog::Write(LOGLEVEL_DEBUG, "FileStateMachine->UnLinkUserDevice(): nothing changed");
            else
                ZLog::Write(LOGLEVEL_DEBUG, "FileStateMachine->UnLinkUserDevice(): unlinked device from user");
                
        }
        catch (PDOException $e) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->UnLinkUserDevice(): unlinking failed!"));
        }
    }

    /**
     * Returns an array with all device ids for a user.
     * If no user is set, all device ids should be returned
     *
     * @param string    $username   (opt)
     *
     * @access public
     * @return array
     */
    public function GetAllDevices($username = false) {
        $pdo_params = array(
            ":username" => $username
        );
        try {
            $sql = "SELECT deviceid FROM sogosync_userdevices";
            if ($username) {
                $sql .= " WHERE username=:username";
            }
            $sth = $this->dbh->prepare($sql);
            $sth->execute($pdo_params);
            ZLog::Write(LOGLEVEL_DEBUG, "Devices($username): ".join(",", $sth->fetchAll(PDO::FETCH_COLUMN, 'DEVICEID')));
            return $sth->fetchAll(PDO::FETCH_COLUMN, 'DEVICEID');
        }
        catch (PDOException $e) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllDevices(): listing devices failed!"));
        }
    }

}
?>
