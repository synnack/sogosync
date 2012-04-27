<?php
/***********************************************
* File      :   caldav.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' which handles the
*               intricacies of generating
*               differentials from static
*               snapshots. This means that the
*               implementation here needs no
*               state information, and can simply
*               return the current state of the
*               messages. The diffbackend will
*               then compare the current state
*               to the known last state of the PDA
*               and generate change increments
*               from that.
*
* Created   :   20.02.2012
*
* Copyright 2012 xbgmsharp <xbgmsharp@gmail.com>
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
* Consult LICENSE file for details
************************************************/

require_once('diffbackend.php');
// This is a carddav client library from https://github.com/graviox/CardDAV-PHP/
require_once('carddav.php');
// This is a carddav parser library from https://github.com/nuovo/vCard-parser/
require_once('vCard.php');

class BackendCarddav extends BackendDiff {
    // SOGoSync version
    const SOGOSYNC_VERSION = '0.2.0';
    // SOGoSync vcard Prodid
    const SOGOSYNC_PRODID = 'SOGoSync';
    private $_collection = array();

    /* Called to logon a user. These are the three authentication strings that you must
     * specify in ActiveSync on the PDA. Normally you would do some kind of password
     * check here. Alternatively, you could ignore the password here and have Apache
     * do authentication via mod_auth_*
     */
    function Logon($username, $domain, $password) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . $username .", " . $domain . ",***)");
	debugLog("CarddavBackend: " . __FUNCTION__ . " - Version  [" . self::SOGOSYNC_VERSION . "]");

        // Confirm PHP-CURL Installed; If Not, Exit
        if (!function_exists("curl_init")) {
            debugLog("ERROR: Carddav Backend requires PHP-CURL");
            return false;
        }

	$url = str_replace('%u', $username, CARDDAV_URL);
	debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
	$this->_carddav = new carddav_backend($url);
	$this->_carddav->set_auth($username, $password);

	if ($this->_carddav->check_connection()) {
	    debugLog("CarddavBackend: " . __FUNCTION__ . " [Logon(): User '". $username ."' is authenticated on CarDAV]");
	    $this->_username = $username;
	    $this->url = $url;
	    return true;
	} else {
	    debugLog("CarddavBackend: " . __FUNCTION__ . " [Logon(): User '". $username ."' is not authenticated on CarDAV]");
	    return false;
	}
    }

    /* Called before shutting down the request to close the IMAP connection
     */
    function Logoff() {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

    }

    /* Called directly after the logon. This specifies the client's protocol version
     * and device id. The device ID can be used for various things, including saving
     * per-device state information.
     * The $user parameter here is normally equal to the $username parameter from the
     * Logon() call. In theory though, you could log on a 'foo', and then sync the emails
     * of user 'bar'. The $user here is the username specified in the request URL, while the
     * $username in the Logon() call is the username which was sent as a part of the HTTP
     * authentication.
     */
    function Setup($user, $devid, $protocolversion) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;

        return true;
    }

    /* Sends a message which is passed as rfc822. You basically can do two things
     * 1) Send the message to an SMTP server as-is
     * 2) Parse the message yourself, and send it some other way
     * It is up to you whether you want to put the message in the sent items folder. If you
     * want it in 'sent items', then the next sync on the 'sent items' folder should return
     * the new message as any other new message in a folder.
     */
    function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	// Unimplemented
        return false;
    }

    /* Should return a wastebasket folder if there is one. This is used when deleting
     * items; if this function returns a valid folder ID, then all deletes are handled
     * as moves and are sent to your backend as a move. If it returns FALSE, then deletes
     * are always handled as real deletes and will be sent to your importer as a DELETE
     */
    function GetWasteBasket() {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
        return false;
    }

    /* Should return a list (array) of messages, each entry being an associative array
     * with the same entries as StatMessage(). This function should return stable information; ie
     * if nothing has changed, the items in the array must be exactly the same. The order of
     * the items within the array is not important though.
     *
     * The cutoffdate is a date in the past, representing the date since which items should be shown.
     * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
     * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
     * will work OK apart from that.
     */
    function GetMessageList($folderid, $cutoffdate) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

        // Get list of vcard for one addressbook ($folderid)
        // for each vcard send the etag as MOD and the UID as ID
/*	$messagelist = array();
	if(strstr((string)$folderid, "public")) { return $messagelist; } // if public skip as it is handle by GAL
	$url = $this->url . $folderid . "/";
	debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
	$this->_carddav->set_url($url);
	$vcardlist = $this->_carddav->get(true, false);
	if (empty($vcardlist)) { return $messagelist; }
	$xmlvcardlist = new SimpleXMLElement($vcardlist);
	foreach ($xmlvcardlist->element as $vcard) {
		$id = (string)$vcard->id->__toString();
		$this->_collection[$id] = $vcard;
		$messageslist[] = $this->StatMessage($folderid, $id);
	}
	return $messagelist;
*/

	$messagelist = array();
	if(strstr((string)$folderid, "public")) { return $messagelist; } // if public skip as it is handle by GAL
	$url = $this->url . $folderid . "/";
	debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
	$this->_carddav->set_url($url);
	$vcardlist = $this->_carddav->get(false, false);
	if (empty($vcardlist)) { return $messagelist; }
	$xmlvcardlist = new SimpleXMLElement($vcardlist);
	foreach ($xmlvcardlist->element as $vcard) {
		$message = array();
		$message["mod"] = (string)$vcard->etag;
		$message["id"] = (string)$vcard->id;
		$message["flags"] = "0";
		$messagelist[] = $message;
		debugLog("CarddavBackend: " . __FUNCTION__ ." - in Abook [". $folderid ."] vCard Id  [". $message["id"] ."] vCard etag [". $message["mod"] ."]");
	}
	return $messagelist;
    }

    /* This function is analogous to GetMessageList.
     */
    function GetFolderList() {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	// Get list of addressboook
	// for each addressbook send the name as MOD and the id as ID
	$folderlist = array();
	$url = $this->url;
	debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
	$this->_carddav->set_url($url);
        $abooklist = $this->_carddav->get(false, false);
	if (empty($abooklist)) { return $folderlist; }
	$xmlabooklist = new SimpleXMLElement($abooklist);
	foreach ($xmlabooklist->addressbook_element as $response) {
		if(strstr(basename((string)$response->url->__toString()), "public")) { continue; } // if public skip as it is handle by GAL
		$folderlist[] = $this->StatFolder(basename((string)$response->url));
	}
/*	if (empty($abooklist)) { return $folderlist; }
        $xmlabooklist = new SimpleXMLElement($abooklist);
        foreach ($xmlabooklist->addressbook_element as $response) {
		if(strstr(basename((string)$response->url), "public")) { continue; } // if public skip as it is handle by GAL
	        $folder = array();
	        $folder["id"] = basename((string)$response->url);
	        $folder["parent"] = "0";
	        $folder["mod"] = (string)$response->display_name;
	        $folderlist[] = $folder;
		debugLog("CarddavBackend: " . __FUNCTION__ . " - in Abook [". $folder["id"] ."] Abook Name [". $folder["mod"]. "]");
	}
*/	return $folderlist;
    }

    /* GetFolder should return an actual SyncFolder object with all the properties set. Folders
     * are pretty simple really, having only a type, a name, a parent and a server ID.
     */
    function GetFolder($id) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

        // for one Addressboook ($id)
        // send the id as serverid and the name as displayname
        $url = $this->url;
	debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
        $this->_carddav->set_url($url);
        $abooklist = $this->_carddav->get(false, false);
	$folder = new SyncFolder();
	if (empty($abooklist)) { return folder; }
        $xmlabooklist = new SimpleXMLElement($abooklist);
        foreach ($xmlabooklist->addressbook_element as $response) {
		if(strstr(basename((string)$response->url->__toString()), "public")) { continue; } // if public skip as it is handle by GAL
		if(basename((string)$response->url->__toString()) === $id) {
			$folder->serverid = basename((string)$response->url->__toString());
			$folder->displayname = (string)$response->display_name->__toString();
			$folder->parentid = "0";
			if (defined(CARDDAV_PERSONAL) && strtolower($id) == CARDDAV_PERSONAL)
			{
				$folder->type = SYNC_FOLDER_TYPE_USER_CONTACT;
			}
			else
			{
				$folder->type = SYNC_FOLDER_TYPE_CONTACT;
			}
			debugLog("CarddavBackend: " . __FUNCTION__ . " - Abook Id  [". $folder->serverid ."] Abook Name [". $folder->displayname ."]");
			return $folder;
		}
	}
	return folder;
    }

    /* Return folder stats. This means you must return an associative array with the
     * following properties:
     * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
     *         How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
     * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
     * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
     *          the folder has not changed. In practice this means that 'mod' can be equal to the folder name
     *          as this is the only thing that ever changes in folders. (the type is normally constant)
     */
    function StatFolder($id) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

	$val = $this->GetFolder($id);
	$folder = array();
	$folder["id"] = $id;
	$folder["parent"] = $val->parentid;
	$folder["mod"] = $val->displayname;
	debugLog("CarddavBackend: " . __FUNCTION__ . " - Abook Id  [". $folder["id"] ."] Abook Name [". $folder["mod"] ."]");
	return $folder;

        // for one Addressboook ($id)
        // send the id as id and the name as mod
/*        $url = $this->url;
	debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
        $this->_carddav->set_url($url);
        $abooklist = $this->_carddav->get(false, false);
	if (empty($abooklist)) { return false; }
        $xmlabooklist = new SimpleXMLElement($abooklist);
        foreach ($xmlabooklist->addressbook_element as $response) {
		if(strstr(basename((string)$response->url), "public")) { continue; } // if public skip as it is handle by GAL
		if(basename((string)$response->url) === $id) {
			$folder = array();
			$folder["id"] = basename((string)$response->url);
			$folder["parent"] = "0";
			$folder["mod"] = (string)$response->display_name;
			debugLog("CarddavBackend: " . __FUNCTION__ . " - Abook Id  [". $folder["id"] ."] Abook Name [". $folder["mod"] ."]");
			return $folder;
		}
	}
	return false;
*/    }

    /* Creates or modifies a folder
     * "folderid" => id of the parent folder
     * "oldid" => if empty -> new folder created, else folder is to be renamed
     * "displayname" => new folder name (to be created, or to be renamed to)
     * "type" => folder type, ignored in IMAP
     *
     */
    function ChangeFolder($folderid, $oldid, $displayname, $type){
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    /* Should return attachment data for the specified attachment. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment. So, you should
     * encode any information you need to find the attachment in that 'attname' property.
     */
    function GetAttachmentData($attname) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    /* StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
     * 'id'     => Server unique identifier for the message. Again, try to keep this short (under 20 chars)
     * 'flags'  => simply '0' for unread, '1' for read
     * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
     *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
     *             time for this field, which will change as soon as the contents have changed.
     */
    function StatMessage($folderid, $id) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

        // for one vcard ($id) of one addressbook ($folderid)
        // send the etag as mod and the UUID as id
        // the same as in GetMsgList
        $url = $this->url . $folderid . "/";
        $this->_carddav->set_url($url);
	debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
        $data = $this->_carddav->get_xml_vcard($id);
	$message = array();
	if ($data === false) { return $message; }
	$xmlvcard = new SimpleXMLElement($data);
	foreach($xmlvcard->element as $vcard) {
		$message["mod"] = (string)$vcard->etag->__toString();
		$message["id"] = (string)$vcard->id->__toString();
		$message["flags"] = "0";
		debugLog("CarddavBackend: " . __FUNCTION__ . " - in Abook [". $folderid ."] vCard Id  [". $message["id"] ."] vCard etag [". $message["mod"] ."]");
	}
	return $message;
    }

     /* GetMessage should return the actual SyncXXX object type. You may or may not use the '$folderid' parent folder
     * identifier here.
     * Note that mixing item types is illegal and will be blocked by the engine; ie returning an Email object in a
     * Tasks folder will not do anything. The SyncXXX objects should be filled with as much information as possible,
     * but at least the subject, body, to, from, etc.
     *
     * Truncsize is the size of the body that must be returned. If the message is under this size, bodytruncated should
     * be 0 and body should contain the entire body. If the body is over $truncsize in bytes, then bodytruncated should
     * be 1 and the body should be truncated to $truncsize bytes.
     *
     * Bodysize should always be the original body size.
     */
    function GetMessage($folderid, $id, $truncsize, $mimesupport = 0) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");

        // for one vcard ($id) of one addressbook ($folderid)
        // send all vcard details in a SyncContact format
        $url = $this->url . $folderid . "/";
        $this->_carddav->set_url($url);
	debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
        $data = $this->_carddav->get_vcard($id);
	if ($data === false) { return false; }
	debugLog("CarddavBackend: vCard[" . $data. "]");

	$mapping = array(
		'FN' => 'fileas',
		'N' => 'lastname;firstname',
		'NICKNAME' => 'nickname', // Only one in active Sync
		'TEL;TYPE=home' => 'homephonenumber',
		'TEL;TYPE=cell' => 'mobilephonenumber',
		'TEL;TYPE=work' => 'businessphonenumber',
		'TEL;TYPE=fax' => 'businessfaxnumber',
		'TEL;TYPE=pager' => 'pagernumber',
		'EMAIL;TYPE=work' => 'email1address',
		'EMAIL;TYPE=home' => 'email2address',
		'URL;TYPE=home' => 'webpage', // does not exist in ActiveSync
		'URL;TYPE=work' => 'webpage',
		'BDAY' => 'birthday',
//		'ROLE' => 'jobtitle', iOS take it as 'TITLE' Does not make sense?
		'TITLE' => 'jobtitle',
		'NOTE' => 'body',
		'ORG' => 'companyname;department',
		'ADR;TYPE=work' => ';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry',
		'ADR;TYPE=home' => ';;homestreet;homecity;homestate;homepostalcode;homecountry',
//		'PHOTO;VALUE=BINARY;TYPE=JPEG;ENCODING=B' => 'picture', // Need improve parsing waiting on vCard parser to suport photo
		'PHOTO;ENCODING=BASE64;TYPE=JPEG' => 'picture',
		'CATEGORIES' => 'categories', // Can not create categorie on iOS, test with hotmail.com and no sync or view of categories?
		'X-AIM' => 'imaddress',
	);

	$message = new SyncContact();
	$message->lastname = "unknow";
	// Removing/changing inappropriate newlines, i.e., all CRs or multiple newlines are changed to a single newline
        $data = str_replace("\x00", '', $data);
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = preg_replace('/(\n)([ \t])/i', '', $data);
        // Joining multiple lines that are split with a hard wrap and indicated by an equals sign at the end of line
	// Generate a photo issue
        //$data = str_replace("=\n", '', $data);
        // Joining multiple lines that are split with a soft wrap (space or tab on the beginning of the next line)
        $data = str_replace(array("\n ", "\n\t"), '', $data);

	$lines = explode("\n", $data);
	foreach($lines as $line) {
		if (trim($line) == '') { continue; }
		$pos = strpos($line, ':');
		if ($pos === false) { continue; }
		$vcardparse = explode(":", $line);
		if (!isset($vcardparse[0]) || !isset($vcardparse[1]) || empty($vcardparse[0]) || empty($vcardparse[1]) ) { continue; }
		foreach ($mapping as $vcard => $ms) {
			if ((string)$vcardparse[0] === (string)$vcard) {
				if ( ($vcardparse[0] === 'N') || ($vcardparse[0] === 'ORG') || (strstr($vcardparse[0],'ADR') != false) ) {
					debugLog("CarddavBackend: " . __FUNCTION__ . " - vCard N - ORG - ADR");
					$parts = explode(";", $vcardparse[1]);
					$value = explode(";", $ms);
					for ($i=0;$i<sizeof($parts);$i++) {
						if (empty($value[$i]) || empty($parts[$i])) { continue; }
						debugLog("CarddavBackend: " . __FUNCTION__ . " - vCard [" . $value[$i] ."]");
						$message->$value[$i] = (string)$parts[$i];
					}
                                } else if ($vcardparse[0] === 'CATEGORIES') {
                                        debugLog("CarddavBackend: " . __FUNCTION__ . " - vCard CATEGORIES");
					$message->$ms = explode(',', $vcardparse[1]);
				} else if ($vcardparse[0] === 'NOTE') {
					debugLog("CarddavBackend: " . __FUNCTION__ . " - vCard NOTE");
					$message->$ms = (string)$vcardparse[1];
					$message->bodysize = strlen($vcardparse[1]);
					$message->bodytruncated = 0;
				} else if ($vcardparse[0] === 'PHOTO') {
					debugLog("CarddavBackend: " . __FUNCTION__ . " - vCard PHOTO");
					// Check for base64 encode so not need
					//$message->picture = base64_encode($vcardparse[1]);
					//$message->picture = imap_binary($vcardparse[1]);
					$message->picture = $vcardparse[1];
				} else if ($vcardparse[0] === 'BDAY') {
					debugLog("CarddavBackend: " . __FUNCTION__ . " - vCard BDAY");
					$tz = date_default_timezone_get();
					date_default_timezone_set('UTC');
					$message->$ms = strtotime($vcardparse[1]);
					date_default_timezone_set($tz);
				} else {
					$message->$ms = (string)$vcardparse[1];
				}
			}
		}
	}
	debugLog("CarddavBackend: " . __FUNCTION__ . " - in Abook [". $folderid ."] vCard id [". $id ."] vCard Name [". $message->lastname ."]");
	return $message;
    }

    /* This function is called when the user has requested to delete (really delete) a message. Usually
     * this means just unlinking the file its in or somesuch. After this call has succeeded, a call to
     * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the PDA
     * as it will be seen as a 'new' item. This means that if you don't implement this function, you will
     * be able to delete messages on the PDA, but as soon as you sync, you'll get the item back
     */
    function DeleteMessage($folderid, $id) {
        debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
        $url = $this->url . $folderid . "/";
        $this->_carddav->set_url($url);
        debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
        return $this->_carddav->delete($id);
    }

    /* This should change the 'read' flag of a message on disk. The $flags
     * parameter can only be '1' (read) or '0' (unread). After a call to
     * SetReadFlag(), GetMessageList() should return the message with the
     * new 'flags' but should not modify the 'mod' parameter. If you do
     * change 'mod', simply setting the message to 'read' on the PDA will trigger
     * a full resync of the item from the server
     */
    function SetReadFlag($folderid, $id, $flags) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    /* This function is called when a message has been changed on the PDA. You should parse the new
     * message here and save the changes to disk. The return value must be whatever would be returned
     * from StatMessage() after the message has been saved. This means that both the 'flags' and the 'mod'
     * properties of the StatMessage() item may change via ChangeMessage().
     * Note that this function will never be called on E-mail items as you can't change e-mail items, you
     * can only set them as 'read'.
     */
    function ChangeMessage($folderid, $id, $message) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . $folderid . "," . $id . ")");

        $mapping = array(
                'fileas' => 'FN',
                'lastname;firstname' => 'N',
                'nickname' => 'NICKNAME',
                'homephonenumber' => 'TEL;TYPE=home',
                'mobilephonenumber' => 'TEL;TYPE=cell',
                'businessphonenumber' => 'TEL;TYPE=work',
                'businessfaxnumber' => 'TEL;TYPE=fax',
                'pagernumber' => 'TEL;TYPE=pager',
                'email1address' => 'EMAIL;TYPE=work',
                'email2address' => 'EMAIL;TYPE=home',
                //'webpage' => 'URL;TYPE=home', does not exist in ActiveSync
                'webpage' => 'URL;TYPE=work',
                //'birthday' => 'BDAY', // handle separetly
                //'jobtitle' => 'ROLE', // iOS take it as 'TITLE' Does not make sense??
                'jobtitle' => 'TITLE',
                'body' => 'NOTE',
                'companyname;department' => 'ORG',
                ';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry' => 'ADR;TYPE=work',
                ';;homestreet;homecity;homestate;homepostalcode;homecountry' => 'ADR;TYPE=home',
                //'picture' => 'PHOTO;BASE64', // handle separetly
                //'categories' => 'CATEGORIES', // handle separetly, but i am unable to create categories form iOS
                'imaddress' => 'X-AIM',
        );

        $data = "BEGIN:VCARD\n";
        if ($id) {
                $data .= "UID:". $id .".vcf\n";
        } else {
                $UUID = $this->generate_uuid();
                $data .= "UID:". $UUID .".vcf\n";
        }
        $data .= "VERSION:3.0\nPRODID:-//". self::SOGOSYNC_PRODID ." ". self::SOGOSYNC_VERSION ."//NONSGML ". self::SOGOSYNC_PRODID . " AddressBook//EN\n";

        foreach($mapping as $ms => $vcard){
                $val = '';
                $value = explode(';', $ms);
                foreach($value as $i){
                        if(!empty($message->$i))
                                $val .= $message->$i;
                        $val.=';';
                }
                $val = substr($val,0,-1);
                if(empty($val)) { continue; }
                $data .= $vcard.":".$val."\n";
        }
        if(!empty($message->categories))
                $data .= "CATEGORIES:".implode(',', $message->categories)."\n";
        if(!empty($message->picture))
                // FIXME first line 50 char next one 74
                // Apparently iOS send the file on BASE64
                $data .= "PHOTO;ENCODING=BASE64;TYPE=JPEG:".substr(chunk_split($message->picture, 50, "\n "), 0, -1);
        if(isset($message->birthday))
                $data .= "BDAY:".date('Y-m-d', $message->birthday)."\n";
        $data .= "END:VCARD";

        debugLog("CarddavBackend: vCard[". $data ."]");
        //debugLog("CarddavBackend: vCard[". print_r($message,true) ."]");

        $url = $this->url . $folderid . "/";
        $this->_carddav->set_url($url);
        debugLog("CarddavBackend: " . __FUNCTION__ . " - URL  [" . $url . "]");
        if ($id)
                $this->_carddav->update($data, $id);
        else
                $this->_carddav->add($data, $UUID);

        return $this->StatMessage($folderid, $id);
    }

    /* This function is called when the user moves an item on the PDA. You should do whatever is needed
     * to move the message on disk. After this call, StatMessage() and GetMessageList() should show the items
     * to have a new parent. This means that it will disappear from GetMessageList() will not return the item
     * at all on the source folder, and the destination folder will show the new message
     */
    function MoveMessage($folderid, $id, $newfolderid) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
        return false;
    }

    /* Parse the message and return only the plaintext body
     */
    function getBody($message) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    // Get all parts in the message with specified type and concatenate them together, unless the
    // Content-Disposition is 'attachment', in which case the text is apparently an attachment
    function getBodyRecursive($message, $subtype, &$body) {
	debugLog("CarddavBackend: " . __FUNCTION__ . "(" . implode(", ", func_get_args()) . ")");
	return false;
    }

    private function MydebugLog($fuc, $str)
    {
                debugLog("CardavBackend: " . $fuc . " [". $str ."]");
    }

   private function generate_uuid() {
        // Which format?
        $md5 = md5(uniqid('', true));
        // 33CABD2C-5386-D460-2577-A63C141405F4
/*      return strtoupper(substr($md5, 0, 8 ) . '-' . substr($md5, 8, 4) . '-' .
                substr($md5, 12, 4) . '-' .
                substr($md5, 16, 4) . '-' .
                substr($md5, 20, 12));

        // 6F53-4F561080-F-7B4FC200
        return strtoupper(substr($md5, 0, 4 ) . '-' . substr($md5, 4, 8) . '-' .
                substr($md5, 12, 1) . '-' .
                substr($md5, 14, 8));
*/
	// 20120427T111858Z-6F53-4F561080-F-7B4FC200
	return strtoupper(gmdate("Ymd\THis\Z") .'-'. substr($md5, 0, 4 ) . '-' .
		substr($md5, 4, 8) . '-' .
		substr($md5, 12, 1) . '-' .
		substr($md5, 14, 8));
   }

};

?>
