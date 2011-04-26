<?php
/***********************************************
* File      :   exporter.php
* Project   :   Z-Push
* Descr     :   This is a generic class that is
*               used by both the proxy importer
*               (for outgoing messages) and our
*               local importer (for incoming
*               messages). Basically all shared
*               conversion data for converting
*               to and from MAPI objects is in here.
*
* Created   :   14.02.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
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


/**
 * This is our ICS exporter which requests the actual exporter from ICS and makes sure
 * that the ImportProxies are used.
 */

class ExportChangesICS implements IExportChanges{
    private $_folderid;
    private $_store;
    private $_session;
    private $_restriction;
    private $_truncation;
    private $_flags;
    private $_exporterflags;
    private $exporter;

    /**
     * Constructor
     *
     * @param mapisession       $session
     * @param mapistore         $store
     * @param string             (opt)
     *
     * @access public
     */
    public function ExportChangesICS($session, $store, $folderid = false) {
        // Open a hierarchy or a contents exporter depending on whether a folderid was specified
        $this->_session = $session;
        $this->_folderid = $folderid;
        $this->_store = $store;

        if($folderid) {
            $entryid = mapi_msgstore_entryidfromsourcekey($store, $folderid);
        } else {
            $storeprops = mapi_getprops($this->_store, array(PR_IPM_SUBTREE_ENTRYID));
            $entryid = $storeprops[PR_IPM_SUBTREE_ENTRYID];
        }

        // Folder available ?
        if(!$entryid) {
            // TODO: throw exception with status
            if ($folderid)
                writeLog(LOGLEVEL_WARN, "ExportChangesICS->Constructor: Folder not found: " . bin2hex($folderid));
            else
                writeLog(LOGLEVEL_WARN, "ExportChangesICS->Constructor: Store root not found");

            $this->importer = false;
            return;
        }

        $folder = mapi_msgstore_openentry($this->_store, $entryid);
        if(!$folder) {
            $this->exporter = false;
            // TODO: return status if available
            writeLog(LOGLEVEL_WARN, "ExportChangesICS->Constructor: can not open folder:".bin2hex($folderid));
            return;
        }

        // Get the actual ICS exporter
        if($folderid) {
            $this->exporter = mapi_openproperty($folder, PR_CONTENTS_SYNCHRONIZER, IID_IExchangeExportChanges, 0 , 0);
        } else {
            $this->exporter = mapi_openproperty($folder, PR_HIERARCHY_SYNCHRONIZER, IID_IExchangeExportChanges, 0 , 0);
        }
    }

    /**
     * Configures the exporter
     *
     * @param object        $importer
     * @param string        $mclass
     * @param int           $restrict       FilterType
     * @param string        $syncstate
     * @param int           $flags
     * @param int           $truncation     bytes
     *
     * @access public
     * @return boolean
     */
    public function Config($syncstate, $flags = 0) {
        $this->_exporterflags = 0;
        $this->_flags = $flags;

        if ($this->exporter === false) {
            // TODO: throw exception with status
            writeLog(LOGLEVEL_FATAL, "ExportChangesICS->Config failed. Exporter not available.");
            return false;
        }

        // change exporterflags if we are doing a ContentExport
        if($this->_folderid) {
            $this->_exporterflags |= SYNC_NORMAL | SYNC_READ_STATE;

            // Initial sync, we don't want deleted items. If the initial sync is chunked
            // we check the change ID of the syncstate (0 at initial sync)
            // On subsequent syncs, we do want to receive delete events.
            if(strlen($syncstate) == 0 || bin2hex(substr($syncstate,4,4)) == "00000000") {
                writeLog(LOGLEVEL_DEBUG, "synching inital data");
                $this->_exporterflags |= SYNC_NO_SOFT_DELETIONS | SYNC_NO_DELETIONS;
            }
        }

        if($this->_flags & BACKEND_DISCARD_DATA)
            $this->_exporterflags |= SYNC_CATCHUP;

        // Put the state information in a stream that can be used by ICS
        $stream = mapi_stream_create();
        if(strlen($syncstate) > 0)
            mapi_stream_write($stream, $syncstate);
        else
            mapi_stream_write($stream, hex2bin("0000000000000000"));

        $this->statestream = $stream;
    }

    /**
     * Sets additional parameters
     *
     * @param string        $mclass
     * @param int           $restrict       FilterType
     * @param int           $truncation     bytes
     *
     * @access public
     * @return boolean
     */
    // TODO eventually it's interesting to create a class which contains these kind of additional information (easier to extend!)
    public function ConfigContentParameters($mclass, $restrict, $truncation) {
        switch($mclass) {
            case "Email":
                $this->_restriction = $this->_getEmailRestriction(getCutOffDate($restrict));
                break;
            case "Calendar":
                $this->_restriction = $this->_getCalendarRestriction(getCutOffDate($restrict));
                break;
            default:
            case "Contacts":
            case "Tasks":
                $this->_restriction = false;
                break;
        }

        $this->_restriction = $restrict;
        $this->_truncation = $truncation;
    }


    /**
     * Sets the importer the exporter will sent it's changes to
     * and initializes the Exporter
     *
     * @param object        &$importer  Implementation of IImportChanges
     *
     * @access public
     * @return boolean
     */
    public function InitializeExporter(&$importer) {
        // Because we're using ICS, we need to wrap the given importer to make it suitable to pass
        // to ICS. We do this in two steps: first, wrap the importer with our own PHP importer class
        // which removes all MAPI dependency, and then wrap that class with a C++ wrapper so we can
        // pass it to ICS

        if($this->exporter === false || !isset($this->statestream) || !isset($this->_flags) || !isset($this->_exporterflags) ||
            ($this->_folderid && (!isset($this->_restriction)  || !isset($this->_truncation))) ) {
            // TODO: throw exception with status
            writeLog(LOGLEVEL_WARN, "ExportChangesICS->Config failed. Exporter not available.!!!!!!!!!!!!!");
            return false;
        }

        if($this->_folderid) {
            // PHP wrapper
            $phpwrapper = new PHPContentsWrapper($this->_session, $this->_store, $this->_folderid, $importer, $this->_truncation);

            // ICS c++ wrapper
            $mapiimporter = mapi_wrap_importcontentschanges($phpwrapper);
        } else {
            $phpwrapper = new PHPHierarchyWrapper($this->_store, $importer);
            $mapiimporter = mapi_wrap_importhierarchychanges($phpwrapper);
        }

        if($this->_folderid)
            $includeprops = false;
        else
            $includeprops = array(PR_SOURCE_KEY, PR_DISPLAY_NAME);

        $ret = mapi_exportchanges_config($this->exporter, $this->statestream, $this->_exporterflags, $mapiimporter, $this->_restriction, $includeprops, false, 1);

        if($ret) {
            $changes = mapi_exportchanges_getchangecount($this->exporter);
            if($changes || !($this->_flags & BACKEND_DISCARD_DATA))
                writeLog(LOGLEVEL_INFO, "Exporter configured successfully. " . $changes . " changes ready to sync.");
        }
        else
            // TODO: throw exception with status
            writeLog(LOGLEVEL_ERROR, "Exporter could not be configured: result: " . sprintf("%X", mapi_last_hresult()));

        return $ret;
    }


    /**
     * Reads the current state from the Exporter
     *
     * @access public
     * @return string
     */
    public function GetState() {
        if(!isset($this->statestream) || $this->exporter === false) {
            // TODO: throw status?
            writeLog(LOGLEVEL_WARN, "Error getting state from Exporter. Not initialized.");
            return false;
        }

        if(mapi_exportchanges_updatestate($this->exporter, $this->statestream) != true) {
            // TODO: throw status?
            writeLog(LOGLEVEL_WARN, "Unable to update state: " . sprintf("%X", mapi_last_hresult()));
            return false;
        }

        mapi_stream_seek($this->statestream, 0, STREAM_SEEK_SET);

        $state = "";
        while(true) {
            $data = mapi_stream_read($this->statestream, 4096);
            if(strlen($data))
                $state .= $data;
            else
                break;
        }

        return $state;
    }

    /**
     * Returns the amount of changes to be exported
     *
     * @access public
     * @return int
     */
     public function GetChangeCount() {
        if ($this->exporter)
            return mapi_exportchanges_getchangecount($this->exporter);
        else
            return 0;
    }

    /**
     * Synchronizes a change
     *
     * @access public
     * @return array
     */
    public function Synchronize() {
        if ($this->exporter) {
            return mapi_exportchanges_synchronize($this->exporter);
        }else
           return false;
    }

    /**----------------------------------------------------------------------------------------------------------
     * private methods
     */
    // TODO: refactor to  mapiutils?


    /**
     * Create a MAPI restriction to use within an email folder which will
    // return all messages since since $timestamp
     *
     * @param long       $timestamp     Timestamp since when to include messages
     *
     * @access private
     * @return array
     */
    private function _getEmailRestriction($timestamp) {
        $restriction = array ( RES_PROPERTY,
                          array (    RELOP => RELOP_GE,
                                    ULPROPTAG => PR_MESSAGE_DELIVERY_TIME,
                                    VALUE => $timestamp
                          )
                      );

        return $restriction;
    }

    /**
     * MAPI returns the propertyid from the propertystring
     *
     * @param string       Mapi property string
     *
     * @access private
     * @return string
     */
    private function _getPropIDFromString($stringprop) {
        return GetPropIDFromString($this->_store, $stringprop);
    }

    /**
     * Create a MAPI restriction to use in the calendar which will
    // return all future calendar items, plus those since $timestamp
     *
     * @param long       $timestamp     Timestamp since when to include messages
     *
     * @access private
     * @return array
     */
    private function _getCalendarRestriction($timestamp) {
        // This is our viewing window
        $start = $timestamp;
        $end = 0x7fffffff; // infinite end

        $restriction = Array(RES_OR,
             Array(
                   // OR
                   // item.end > window.start && item.start < window.end
                   Array(RES_AND,
                         Array(
                               Array(RES_PROPERTY,
                                     Array(RELOP => RELOP_LE,
                                           ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820d"),
                                           VALUE => $end
                                           )
                                     ),
                               Array(RES_PROPERTY,
                                     Array(RELOP => RELOP_GE,
                                           ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820e"),
                                           VALUE => $start
                                           )
                                     )
                               )
                         ),
                   // OR
                   Array(RES_OR,
                         Array(
                               // OR
                               // (EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[end] >= start)
                               Array(RES_AND,
                                     Array(
                                           Array(RES_EXIST,
                                                 Array(ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x8236"),
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_EQ,
                                                       ULPROPTAG => $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223"),
                                                       VALUE => true
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_GE,
                                                       ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x8236"),
                                                       VALUE => $start
                                                       )
                                                 )
                                           )
                                     ),
                               // OR
                               // (!EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[start] <= end)
                               Array(RES_AND,
                                     Array(
                                           Array(RES_NOT,
                                                 Array(
                                                       Array(RES_EXIST,
                                                             Array(ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x8236")
                                                                   )
                                                             )
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_LE,
                                                       ULPROPTAG => $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820d"),
                                                       VALUE => $end
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_EQ,
                                                       ULPROPTAG => $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223"),
                                                       VALUE => true
                                                       )
                                                 )
                                           )
                                     )
                               )
                         ) // EXISTS OR
                   )
             );        // global OR

        return $restriction;
    }
}


?>