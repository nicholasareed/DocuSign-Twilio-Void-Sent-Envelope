<?php 

include_once 'include/account_creds.php';
include_once 'api/APIService.php';

if(!isset($_GET['step'])){
	$step = '1';
} else {
	$step = $_GET['step'];
}

// Switch Statement for choosing Step
switch($step){

/*-------------------- Step 1 (Get Recent Envelopes) --------------------*/
	
		case '1':
			// Get Envelopes Sent
			$allSent = getFolderItems();
			$sent = array();
			foreach($allSent->GetFolderItemsResult->FolderItems->FolderItem as $item){
				// Get only the actually Sent ones
				if($item->Status == 'Sent'){
					$item->TimeAgo = timeAgo($item->Sent,1);
					array_push($sent,$item);
				}
			}
			
			// Sort by Sent time
			usort($sent, "compareSentTime");
			
			// Output TwiML
			header("content-type: text/xml");
			echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			?>
				<Response>
					<Gather action="sent_to_void.php?step=2" numDigits="1" method="POST">
					<?
						$i = 1;
						foreach($sent as $item){
					?>
							<Say>Press <?= $i; ?> for <?= $item->Subject; ?>. Sent <?= $item->TimeAgo;?> ago.</Say>
					<?
							$i++;
							if($i > 9){
								break;
							}
						}
					?>
					</Gather>
				</Response>
<?
			break;
			
	/*-------------------- Step 2 (Confirm Envelope) --------------------*/
	
		case '2': 
			// Get the Digit
			if(strlen($_POST['Digits']) != 1){
				header("Location: sent_to_void.php");
				die;
			}
			
			if($_POST['Digits'] < 1 || $_POST['Digits'] > 9){
				header("Location: sent_to_void.php");
				die;
			}
			
			$digit = $_POST['Digits'];
			
			// Get Envelopes Sent
			$allSent = getFolderItems();
			$sent = array();
			$i = 1;
			foreach($allSent->GetFolderItemsResult->FolderItems->FolderItem as $item){
				// Get only the actually Sent ones
				if($item->Status == 'Sent'){
					$item->TimeAgo = timeAgo($item->Sent,1);
					array_push($sent,$item);
					$i++;
				}
			}
			
			// Sort by Sent time
			usort($sent, "compareSentTime");
			
			// Get the right Envelope
			$envelope = array();
			$i = 1;
			foreach($sent as $item){
				if($i == $digit){
					$envelope = $item;
				}
				$i++;
			}
			
			if(empty($envelope)){
				header("Location: sent_to_void.php");
				die;
			}
			
			// Read the Envelope Name, if it is correct, then remove it
			// - Do you want to Void the Envelope "dkflj" sent "2 days ago"
			// - Press 1 to confirm
			// - Press 0 to go back to the previous menu
			
			// Output TwiML
			header("content-type: text/xml");
			echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			?>
				<Response>
					<Gather action="sent_to_void.php?step=3&amp;id=<?= $envelope->EnvelopeId; ?>" numDigits="1">
						<Say>Press 1 to confirm voiding the Envelope. <?= $envelope->Subject; ?>. Sent <?= $item->TimeAgo; ?> ago.</Say>
					</Gather>
				</Response>
<?
			break;
			
		/*-------------------- Step 3 (Void Envelope) --------------------*/
	
		case '3':
			if($_POST['Digits'] != "1"){
				header("Location: sent_to_void.php");
				die;
			}
			
			// Get the envelopeID
			$envelopeID = $_GET['id'];
			
			// Void the Envelope
			$response = voidEnvelope($envelopeID);
			
			// Output TwiML
			header("content-type: text/xml");
			echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			?>
				<Response>
					<Say>Envelope has been voided. Thank you. Goodbye. </Say>
				</Response>
<?
			break;
			
	} // End Switch


	/*-------------------- Utility Functions --------------------*/

	
	function getAPI() {
	    global $api_endpoint;
	    global $IntegratorsKey;
	    global $UserID;
	    global $Password;
	    
	    $api_wsdl = "api/APIService.wsdl";
	    $api_options =  array('location'=>$api_endpoint,'trace'=>true,'features' => SOAP_SINGLE_ELEMENT_ARRAYS);
	    $api = new APIService($api_wsdl, $api_options);
	    $api->setCredentials("[" . $IntegratorsKey . "]" . $UserID, $Password);
	    
	    return $api;
	}
	
	function getFolderItems() {
	  global $AccountID;
	  
	  $api = getAPI();
		
	  // Create the folder filter to specify the scope of your search
	  // Here, we are limiting the item search to the inbox
	  // You can also limit by owner, date, status and position
	  $filter = new FolderFilter();
	  $filter->AccountId = $AccountID;
	  $filter->StartPosition = 0;
	  $filterTypeInfo = new FolderTypeInfo();
	  $filterTypeInfo->FolderType = FolderType::SentItems;
	  $filter->FolderTypeInfo = $filterTypeInfo;
		
	  // Send
	  $getFolderItemsparams = new GetFolderItems();
	  $getFolderItemsparams->FolderFilter = $filter;
	  $response = $api->GetFolderItems($getFolderItemsparams);
		
	  return $response;
	}
	
	
	function compareSentTime($a, $b){
		$a = strtotime($a->Sent);
		$b = strtotime($b->Sent);
		if ($a == $b) {
		    return 0;
		}
		return ($a < $b) ? 1 : -1;
	}
	
	
	function timeAgo($date,$granularity=1) {
	  $date = strtotime($date);
	  $difference = time() - $date;
	  $periods = array('decade' => 315360000,
	      'year' => 31536000,
	      'month' => 2628000,
	      'week' => 604800, 
	      'day' => 86400,
	      'hour' => 3600,
	      'minute' => 60,
	      'second' => 1);
	  $retval = null;
	  
	  if($difference == 0){
	  	return 'a moment';
	  }
	  
	  foreach ($periods as $key => $value) {
	      if ($difference >= $value) {
	          $time = round($difference/$value);
	          $difference %= $value;
	          $retval .= ($retval ? ' ' : '').$time.' ';
	          $retval .= (($time > 1) ? $key.'s' : $key);
	          $granularity--;
	      }
	      if ($granularity == '0') { 
	      	break; 
	      }
	  }
	  return $retval;      
	}
	
	
	function voidEnvelope($envelopeID = '') {
	  $api = getAPI();
		
		$voidEnvelopeparams = new VoidEnvelope();
		$voidEnvelopeparams->EnvelopeID = $envelopeID;
		$voidEnvelopeparams->Reason = "Voided from phone call"; // Turn into transcription
		$response = $api->VoidEnvelope($voidEnvelopeparams);
		
	  return $response;
	}

?>