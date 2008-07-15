<?php
/**
 * This is tnef implementation for z-push. It is based on 
 * Zarafa's tnef implementation.
 * 
 * The ZPush_tnef class does only simple reading of a tnef stream. 
 * Most importantly, we currently only support properties within 
 * the message itself, and do not support recipient tables and 
 * attachment properties within the TNEF data.
 * 
 * The ZPush_tnef class will accept TNEF streams with data about
 * recipients and attachments, but the information will be ignored.
 * 
 * Created on 21.06.2008 by Manfred
 * 
 * For more information on tnef refer to:
 * http://msdn.microsoft.com/en-us/library/ms530652(EXCHG.10).aspx
 * http://msdn.microsoft.com/en-us/library/cc425498(EXCHG.80).aspx
 * 
 * The mapping between Microsoft Mail IPM classes and those used in 
 * MAPI see: http://msdn2.microsoft.com/en-us/library/ms527360.aspx
 */

define('TNEF_SIGNATURE',      0x223e9f78);
define('TNEF_LVL_MESSAGE',    0x01);
define('TNEF_LVL_ATTACHMENT', 0x02);



define('ZP_DWORD', 32);
define('ZP_WORD', 16);
define('ZP_BYTE', 8);

define('ZP_GUID_SIZE', 256);



class ZPush_tnef{
	
	//we need a store in order to get the namedpropers
	function ZPush_tnef($store) {
		$this->_store = $store;
	}
	/*
	* Function reads tnef stream and puts mapi properties into an array.
	*/
	function extractProps($tnefstream, &$mapiprops) {
		
		$hresult = NOERROR;
		$signature = 0; //tnef signature - 32 Bit
		$key = 0; //a nonzero 16-bit unsigned integer
		
		$type = 0; // 32-bit value
		$size = 0; // 32-bit value
		$checksum = 0; //16-bit value
		$component = 0; //8-bit value - either TNEF_LVL_MESSAGE or TNEF_LVL_ATTACHMENT
		$buffer = "";
		
		//mapping between Microsoft Mail IPM classes and those in MAPI
		$aClassMap = array(
			"IPM.Microsoft Schedule.MtgReq"		=> "IPM.Schedule.Meeting.Request",
			"IPM.Microsoft Schedule.MtgRespP"	=> "IPM.Schedule.Meeting.Resp.Pos",
			"IPM.Microsoft Schedule.MtgRespN"	=> "IPM.Schedule.Meeting.Resp.Neg",
			"IPM.Microsoft Schedule.MtgRespA"	=> "IPM.Schedule.Meeting.Resp.Tent",
			"IPM.Microsoft Schedule.MtgCncl"	=> "IPM.Schedule.Meeting.Canceled",
			"IPM.Microsoft Mail.Non-Delivery"	=> "Report.IPM.Note.NDR",
			"IPM.Microsoft Mail.Read Receipt"	=> "Report.IPM.Note.IPNRN",
			"IPM.Microsoft Mail.Note"			=> "IPM.Note",
			"IPM.Microsoft Mail.Note"			=> "IPM",
		);
		
		//read signature
		$hresult = $this->_readFromTnefStream($tnefstream, ZP_DWORD, $signature);
		if ($hresult !== NOERROR) {
debugLog("====== TNEF SIGNATURE:".bin2hex($tnefstream));		
			debugLog("There was an error reading tnef signature");
			return $hresult;
		}
		
		//check signature
		if ($signature != TNEF_SIGNATURE) {
			debugLog("Corrupt signature.");
			return MAPI_E_CORRUPT_DATA;
		}
		
		//read key
		$hresult = $this->_readFromTnefStream($tnefstream, ZP_WORD, $key);
		if ($hresult !== NOERROR) {
			debugLog("There was an error reading tnef key.");
			return $hresult;
		}
		
		// File is made of blocks, with each a type and size. Component and Key are ignored.
		while(1) {
			//the stream is empty. exit
			if (strlen($tnefstream) == 0) return NOERROR;
			
			//read component - it is either TNEF_LVL_MESSAGE or TNEF_LVL_ATTACHMENT
			$hresult = $this->_readFromTnefStream($tnefstream, ZP_BYTE, $component);
			if ($hresult !== NOERROR) {
				$hresult = NOERROR; //EOF -> no error
				return $hresult;
				break;
			}
						
			//read type
			$hresult = $this->_readFromTnefStream($tnefstream, ZP_DWORD, $type);
			if ($hresult !== NOERROR) {
				debugLog("There was an error reading stream property type");
				return $hresult;
			}

			//read size
			$hresult = $this->_readFromTnefStream($tnefstream, ZP_DWORD, $size);
			if ($hresult !== NOERROR) {
				debugLog("There was an error reading stream property size");
				return $hresult;
			}
						
			if ($size == 0) {
				// do not allocate 0 size data block
				debugLog("Size is 0. Corrupt data.");
				return MAPI_E_CORRUPT_DATA;
			}
			
			//read buffer
			$hresult = $this->_readBuffer($tnefstream, $size, $buffer);
			if ($hresult !== NOERROR) {
				debugLog("There was an error reading stream property buffer");
				return $hresult;
			}
						
			//read checksum
			$hresult = $this->_readFromTnefStream($tnefstream, ZP_WORD, $checksum);
			if ($hresult !== NOERROR) {
				debugLog("There was an error reading stream property checksum.");
				return $hresult;
			}
						
			// Loop through all the blocks of the TNEF data. We are only interested
			// in the properties block for now (0x00069003)			
			switch ($type) {
				case 0x00069003:
					$hresult = $this->_readMapiProps($buffer, $size, $mapiprops);
					if ($hresult !== NOERROR) {
						debugLog("There was an error reading mapi properties' part.");
						return $hresult;
					}
					break;
				case 0x00078008: // PR_MESSAGE_CLASS
					$msMailClass = trim($buffer);
					if (array_key_exists($msMailClass, $aClassMap)) {
						$messageClass = $aClassMap[$msMailClass];
					}
					else {
						$messageClass = $msMailClass;
					}
					$mapiprops[PR_MESSAGE_CLASS] = $messageClass;
					break;
				case 0x00050008: // PR_OWNER_APPT_ID
					$mapiprops[PR_OWNER_APPT_ID] = $buffer;
					break;
				case 0x00040009: // PR_RESPONSE_REQUESTED
					$mapiprops[PR_RESPONSE_REQUESTED] = $buffer;
					break;

				// --- TNEF attachemnts ---
				case 0x00069002:
					break;
				case 0x00018010:		// PR_ATTACH_FILENAME
					break;
				case 0x00068011:		// PR_ATTACH_RENDERING, extra icon information
					break;
				case 0x0006800f:		// PR_ATTACH_DATA_BIN, will be set via OpenProperty() in ECTNEF::Finish()
					break;
				case 0x00069005:		// Attachment property stream
					break;
				default:
					// Ignore this block
					break;
			}
			
		}
		return NOERROR;
	
	}
	
	/*
	* Reads a given number of bits from stream and converts them from little indian in a "normal" order. The function
	* also cuts the tnef stream after reading.
	*/
	function _readFromTnefStream(&$tnefstream, $bits, &$element) {
		$bytes = $bits / 8;
		
		$part = substr($tnefstream, 0, $bytes);
		$packs = array();
		
		switch ($bits) {
			case ZP_DWORD:
				$packs = unpack("V", $part);
				break;
			case ZP_WORD:
				$packs = unpack("v", $part);
				break;
			case ZP_BYTE:
				$packs[1] = ord($part[0]);
				break;
			default:
				$packs = array();
				break;
		}
		
		if (empty($packs) || !isset($packs[1])) return MAPI_E_CORRUPT_DATA;
		
		$tnefstream = substr($tnefstream, $bytes);
		$element = $packs[1];
		return NOERROR;
	}
	
	/*
	* Reads a given number of bits from stream and puts them into $element. The function
	* also cuts the tnef stream after reading.
	*/	
	function _readBuffer(&$tnefstream, $bytes, &$element) {
		
		$element = substr($tnefstream, 0, $bytes);
		$tnefstream = substr($tnefstream, $bytes);
		return NOERROR;
		
	}
	
	/*
	* Reads mapi props from buffer into an anrray.
	*/
	function _readMapiProps (&$buffer, $size, &$mapiprops) {
		$nrprops = 0;
		//get number of mapi properties
		$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $nrprops);
		if ($hresult !== NOERROR) {
				debugLog("There was an error getting the number of mapi properties in stream.");
				return $hresult;
		}

		$size -= 4;

//debugLog("nrprops:$nrprops");
		//loop through all the properties and add them to our internal list
		while($nrprops) {
//debugLog("\tPROP:$nrprops");			
			$hresult = $this->_readSingleMapiProp($buffer, $size, $read, $mapiprops);
			if ($hresult !== NOERROR) {
					debugLog("There was an error reading a mapi property.");
		            debugLog("result: " . sprintf("%x", $hresult));

					return $hresult;
			}
			$nrprops--;
		}
debugLog("final size:$size");		
		return NOERROR;
	}
	
	/*
	* Reads a single mapi prop.
	*/
	function _readSingleMapiProp(&$buffer, &$size, &$read, &$mapiprops) {
		$propTag = 0;
		$len = 0;
		$origSize = $size;
		$isNamedId = 0;
		$namedProp = 0;
		$count = 0;
		$mvProp = 0;
		$guid = 0;
		
		if($size < 8) {
			return MAPI_E_NOT_FOUND;
		}
		
		$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $propTag);
		if ($hresult !== NOERROR) {
			debugLog("There was an error reading a mapi property tag from the stream.");
			return $hresult;
		}
		$size -= 4;
//debugLog("mapi prop tag :".dechex(mapi_prop_id($propTag)));
//debugLog("mapi prop type:".dechex(mapi_prop_type($propTag)));

		if (mapi_prop_id($propTag) >= 0x8000) {
		// Named property, first read GUID, then name/id
			if($size < 24) {
				debugLog("Corrupt guid size for named property:".dechex($propTag));
				return MAPI_E_CORRUPT_DATA;
			}
			//strip GUID & name/id
			$hresult = $this->_readBuffer($buffer, 16, $guid);
			if ($hresult !== NOERROR) {
				debugLog("There was an error reading stream property buffer");
				return $hresult;
			}
	
			$size -= 16;
			 //it is not used and is here only for eventual debugging 
			$readableGuid = unpack("VV/v2v/n4n", $guid);
			$readableGuid = sprintf("{%08x-%04x-%04x-%04x-%04x%04x%04x}",$readableGuid['V'], $readableGuid['v1'], $readableGuid['v2'],$readableGuid['n1'],$readableGuid['n2'],$readableGuid['n3'],$readableGuid['n4']);
//debugLog("guid:$readableGuid");
			
//debugLog("named:".bin2hex(substr($buffer, 0, 8)));

			$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $isNamedId);
			if ($hresult !== NOERROR) {
				debugLog("There was an error reading stream property checksum.");
				return $hresult;
			}
			$size -= 4;

//debugLog("isNamedId:".dechex($isNamedId));

			if($isNamedId != 0) {
				// A string name follows
				//read length of the property
				$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $len);
				if ($hresult !== NOERROR) {
					debugLog("There was an error reading mapi property's length");
					return $hresult;
				}
				$size -= 4;
				if ($size < $len) {
					return MAPI_E_CORRUPT_DATA;
				}
				//read the name of the property, eg Keywords
				$hresult = $this->_readBuffer($buffer, $len, $namedProp);
				if ($hresult !== NOERROR) {
					debugLog("There was an error reading stream property buffer");
					return $hresult;
				}
				
//debugLog("named prop:".($namedProp));				
				$size -= $len;
				
				//Re-align
				$buffer = substr($buffer, ($len & 3 ? 4 - ($len & 3) : 0));
				$size -= $len & 3 ? 4 - ($len & 3) : 0;
			}
			else {
			
				$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $namedProp);
				if ($hresult !== NOERROR) {
					debugLog("There was an error reading mapi property's length");
					return $hresult;
				}
//debugLog('named prop:'.dechex($namedProp));

				$size -= 4;
			}
			
			if ($this->_store !== false) {
				$named = mapi_getidsfromnames($this->_store, array($namedProp), array($guid));
				
				$propTag = mapi_prop_tag(mapi_prop_type($propTag), mapi_prop_id($named[0]));
debugLog("ids:".dechex($propTag));
			}
			else {
				debugLog("Store not available. It is impossible to get named properties");				
			}
		}
		
		if($propTag & MV_FLAG) {
			if($size < 4) {
				return MAPI_E_CORRUPT_DATA;
			}
			//read the number of properties
			$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $count);
//debugLog("number of properties:".dechex($count));
			if ($hresult !== NOERROR) {
				debugLog("There was an error reading number of properties for:".dechex($propTag));
				return $hresult;
			}
			$size -= 4;	
		}
		else {
			$count = 1;
		}
		
		for ($mvProp = 0; $mvProp < $count; $mvProp++) {
			switch(mapi_prop_type($propTag) & ~MV_FLAG ) {
				case PT_I2:
				case PT_R4:
				case PT_LONG:
					if($propTag & MV_FLAG) {
						$hresult = $this->_readBuffer($buffer, 4, $mapiprops[$propTag][]);

						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					else {
						$hresult = $this->_readBuffer($buffer, 4, $mapiprops[$propTag]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					$size -= 4;
					break;
				
				
				case PT_BOOLEAN:
					$hresult = $this->_readBuffer($buffer, 4, $mapiprops[$propTag]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					$size -= 4;
					break;
					
				case PT_APPTIME:
				case PT_SYSTIME:
					if($size < 8) {
						return MAPI_E_CORRUPT_DATA;
					}
					if($propTag & MV_FLAG) {
						$hresult = $this->_readBuffer($buffer, 8, $mapiprops[$propTag][]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					} 
					else {
						$hresult = $this->_readBuffer($buffer, 8, $mapiprops[$propTag]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					//we have to convert the filetime to an unixtime timestamp
					$filetime = unpack("V2v", $mapiprops[$propTag]);
					$filetime = hexdec(sprintf("%08x%08x",$filetime['v2'], $filetime['v1']));

					$filetime = ($filetime - 116444736000000000) / 10000000;
					$mapiprops[$propTag] = $filetime;
					// we have to set the start and end times separately because the standard PR_START_DATE and PR_END_DATE aren't enough
					if ($propTag == PR_START_DATE) {
						$namedStartTime = GetPropIDFromString($this->_store, "PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820d");
						$mapiprops[$namedStartTime] = $filetime;
					}
					if ($propTag == PR_END_DATE) {
						$namedStartTime = GetPropIDFromString($this->_store, "PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x820e");
						$mapiprops[$namedStartTime] = $filetime;
					}
					$size -= 8;
					break;

				case PT_DOUBLE:
				case PT_CURRENCY:
				case PT_I8:
					if($size < 8) {
						return MAPI_E_CORRUPT_DATA;
					}
					if($propTag & MV_FLAG) {
						$hresult = $this->_readBuffer($buffer, 8, $mapiprops[$propTag][]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					} 
					else {
						$hresult = $this->_readBuffer($buffer, 8, $mapiprops[$propTag]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					$size -= 8;
					break;					
				
				case PT_STRING8:
					if($size < 8) {
						return MAPI_E_CORRUPT_DATA;
					}
					// Skip next 4 bytes, it's always '1' (ULONG)
					$buffer = substr($buffer, 4);
					$size -= 4;
					
					//read length of the property
					$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $len);
					if ($hresult !== NOERROR) {
						debugLog("There was an error reading mapi property's length");
						return $hresult;
					}
					$size -= 4;
					if ($size < $len) {
						return MAPI_E_CORRUPT_DATA;
					}
					
					if ($propTag & MV_FLAG) {
						$hresult = $this->_readBuffer($buffer, $len, $mapiprops[$propTag][]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					else {
						$hresult = $this->_readBuffer($buffer, $len, $mapiprops[$propTag]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					//location fix. it looks like tnef uses this value for location
					if (mapi_prop_id($propTag) == 0x8342) {
						$namedStartTime = GetPropIDFromString($this->_store, "PT_STRING8:{00062002-0000-0000-C000-000000000046}:0x8208");
						$mapiprops[$namedStartTime] = $filetime;
					}
					
					$size -= $len;
					
					//Re-align
					$buffer = substr($buffer, ($len & 3 ? 4 - ($len & 3) : 0));
					$size -= $len & 3 ? 4 - ($len & 3) : 0;
					break;
					
				case PT_UNICODE:
					if($size < 8) {
						return MAPI_E_CORRUPT_DATA;
					}
					// Skip next 4 bytes, it's always '1' (ULONG)
					$buffer = substr($buffer, 4);
					$size -= 4;
					
					//read length of the property
					$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $len);
					if ($hresult !== NOERROR) {
						debugLog("There was an error reading mapi property's length");
						return $hresult;
					}
					$size -= 4;
					if ($size < $len) {
						return MAPI_E_CORRUPT_DATA;
					}
					//currently unicode strings are not supported bz mapi_setprops, so we'll use PT_STRING8
					$propTag = mapi_prop_tag(PT_STRING8, mapi_prop_id($propTag));

					if ($propTag & MV_FLAG) {
						$hresult = $this->_readBuffer($buffer, $len, $mapiprops[$propTag][]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					else {
						$hresult = $this->_readBuffer($buffer, $len, $mapiprops[$propTag]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					
					//location fix. it looks like tnef uses this value for location
					if (mapi_prop_id($propTag) == 0x8342) {
						$namedStartTime = GetPropIDFromString($this->_store, "PT_STRING8:{00062002-0000-0000-C000-000000000046}:0x8208");
						$mapiprops[$namedStartTime] = $filetime;
					}
					
					//convert from unicode to windows encoding
					$mapiprops[$propTag] = iconv("UCS-2","windows-1252", $mapiprops[$propTag]);
					
					$size -= $len;
					
					//Re-align
					$buffer = substr($buffer, ($len & 3 ? 4 - ($len & 3) : 0));
					$size -= $len & 3 ? 4 - ($len & 3) : 0;
					
					break;
				
				case PT_OBJECT:		// PST sends PT_OBJECT data. Treat as PT_BINARY
				case PT_BINARY:
					if($size < ZP_BYTE) {
						return MAPI_E_CORRUPT_DATA;
					}
					// Skip next 4 bytes, it's always '1' (ULONG)
					$buffer = substr($buffer, 4);
					$size -= 4;
					
					//read length of the property
					$hresult = $this->_readFromTnefStream($buffer, ZP_DWORD, $len);
					if ($hresult !== NOERROR) {
						debugLog("There was an error reading mapi property's length");
						return $hresult;
					}
					$size -= 4;

					if (mapi_prop_type($propTag) == PT_OBJECT) {
						// TODO: IMessage guid [ 0x00020307 C000 0000 0000 0000 00 00 00 46 ]
						$buffer = substr($buffer, 16);
						$size -= 16;
						$len -= 16;
					}
					
					if ($size < $len) {
						return MAPI_E_CORRUPT_DATA;
					}
					
					if ($propTag & MV_FLAG) {
						$hresult = $this->_readBuffer($buffer, $len, $mapiprops[$propTag][]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					else {
						$hresult = $this->_readBuffer($buffer, $len, $mapiprops[$propTag]);
						if ($hresult !== NOERROR) {
							debugLog("There was an error reading stream property buffer");
							return $hresult;
						}
					}
					
					$size -= $len;
					
					//Re-align
					$buffer = substr($buffer, ($len & 3 ? 4 - ($len & 3) : 0));
					$size -= $len & 3 ? 4 - ($len & 3) : 0;
					break;
				
				default:
					return MAPI_E_INVALID_PARAMETER;
					break;
			}
		}	
		return NOERROR;
	}
	
}

function bin2float ($bin) {
    if((ord($bin[0])>>7)==0) $sign=1;
    else $sign=-1;
    if((ord($bin[0])>>6)%2==1) $exponent=1;
    else $exponent=-127;
    $exponent+=(ord($bin[0])%64)*2;
    $exponent+=ord($bin[1])>>7;
   
    $base=1.0;
    for($k=1;$k<8;$k++) {
     $base+=((ord($bin[1])>>(7-$k))%2)*pow(0.5,$k);
    }
    for($k=0;$k<8;$k++) {
     $base+=((ord($bin[2])>>(7-$k))%2)*pow(0.5,$k+8);
    }
    for($k=0;$k<8;$k++) {
     $base+=((ord($bin[3])>>(7-$k))%2)*pow(0.5,$k+16);
    }
   
    $float=(float)$sign*pow(2,$exponent)*$base;
    return $float;
}
?>