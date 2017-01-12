<?php

// THIS WORKS!!! (as of 2014-06-27 11:59 AM) -- Stefan W.
//
// Note: This uses a cert that is downloaded on-the-fly to validate messages.
//
// Details: http://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.html
// 
// 2014-07-26: palantir.fas can't "verifyCertificate" because of an old SSL version.
// However, palantir2.unix.fas (RHEL 6) can "verifyCertificate" just fine!
//
// 2014-10-31: Added special handling of 'INSUFFICIENT_DATA' for ELB 4XX & 5XX HTTP counts.
// The 'INSUFFICIENT_DATA' state is really a Nagios OK because it means there's been NO errors at all,
// and the Cloudwatch ELB 'OK' is really a Nagios Warning because it means there's been SOME errors
// but just not enough to trigger the 'ALARM' condition.
// 
// 2017-01-11: Added handling of incoming SNS messages that are NOT from CloudWatch Alarms.
// This new type of message has "detail-type": "Scheduled Event" which indicates it's coming
// from CloudWatch Events. There is now an AWS Event Rule which fires periodically to generate a
// test SNS message to this webhook script. This will be logged to a specific output file, different
// from the general log file. The output file for the CloudWatch Events SNS messages is monitored 
// by Nagios to make sure it's being updated. If SNS messages fail to be delivered, that Nagios 
// check will notify of the issue. See "AWS incoming SNS messages log: nagios-master-server" on
// https://nagios.huit.harvard.edu/nagios/cgi-bin/status.cgi?host=nagios-master-server


//////
//// CONFIGURATION
//////

error_reporting( E_ALL );
ini_set( 'display_errors', true );
ini_set( 'html_errors', false );

//For Debugging.
$writeToNagios = true;
$logToFile = true;

//Should you need to check that your messages are coming from the correct topicArn
$restrictByTopic = false;
$allowedTopic = "arn:aws:sns:us-east-1:318514470594:WAYJ_NowPlaying_Test";

//For security you can (should) validate the certificate, this does add an additional time demand on the system.
//NOTE: This also checks the origin of the certificate to ensure messages are signed by the AWS SNS SERVICE.
//Since the allowed topicArn is part of the validation data, this ensures that your request originated from
//the service, not somewhere else, and is from the topic you think it is, not something spoofed.
$verifyCertificate = true;

$verifysourceDomain = true;
$sourceDomain = "sns.us-east-1.amazonaws.com";


//////
//// OPERATION
//////

$signatureValid = false;
$safeToProcess = true; //Are Security Criteria Set Above Met? Changed programmatically to false on any security failure.

if( $logToFile ){
	////LOG TO FILE:
	date_default_timezone_set('America/New_York');
	$dateString = date("Y-m-d");
	$logFile = "/var/tmp/sns/" . $dateString . "_aws_sns_receiver.php.log";

	$logFH = fopen($logFile, 'a') or die("Log File Cannot Be Opened.");
	fwrite( $logFH, "==============================================================================================================\n" ) ;
	fwrite( $logFH, __FILE__ . " " . date("Y-m-d H:i:s") . "\n\n" ) ;
	fwrite( $logFH, "Environment REMOTE_ADDR: "     . getenv('REMOTE_ADDR')     . "\n" ) ;
	fwrite( $logFH, "Environment HTTP_USER_AGENT: " . getenv('HTTP_USER_AGENT') . "\n\n" ) ;
}


//Get the raw post data from the request. This is the best-practice method as it does not rely on special php.ini directives
//like $HTTP_RAW_POST_DATA. Amazon SNS sends a JSON object as part of the raw post body.
$json = json_decode(file_get_contents("php://input"));

if ( is_null( $json ) || $json == "" ) {

	echo "Error - no POST data\n" ;
	if ( $logToFile ) {
		fwrite( $logFH, "No POST data - exiting\n" ) ;
		fclose( $logFH ) ;
	}
	exit( 1 ) ;

}

//Check for Restrict By Topic
if ( $restrictByTopic ) {
	if( $allowedTopic != $json->TopicArn ) {
		$safeToProcess = false;
		if( $logToFile ){
			fwrite( $logFH, "ERROR: Allowed Topic ARN: " . $allowedTopic . " DOES NOT MATCH Calling Topic ARN: " . $json->TopicArn . "\n");
		}
	}
}


// Check for Verify Certificate
if( $verifyCertificate ) {

   if( $verifysourceDomain ) {
	// Check For Certificate Source
	$domain = getDomainFromUrl( $json->SigningCertURL );
	if ( $domain != $sourceDomain ) {
		$safeToProcess = false;
		if( $logToFile ) {
			fwrite( $logFH, "Key domain: " . $domain . " is not equal to allowed source domain:" . $sourceDomain. "\n");
		}
	}
   }	


	//Build Up The String That Was Originally Encoded With The AWS Key So You Can Validate It Against Its Signature.
	// http://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.verify.signature.html
	if($json->Type == "SubscriptionConfirmation"){
		$validationString = "";
		$validationString .= "Message\n";
		$validationString .= $json->Message . "\n";
		$validationString .= "MessageId\n";
		$validationString .= $json->MessageId . "\n";
		$validationString .= "SubscribeURL\n";
		$validationString .= $json->SubscribeURL . "\n";
		$validationString .= "Timestamp\n";
		$validationString .= $json->Timestamp . "\n";
		$validationString .= "Token\n";
		$validationString .= $json->Token . "\n";
		$validationString .= "TopicArn\n";
		$validationString .= $json->TopicArn . "\n";
		$validationString .= "Type\n";
		$validationString .= $json->Type . "\n";
	}else{
		$validationString = "";
		$validationString .= "Message\n";
		$validationString .= $json->Message . "\n";
		$validationString .= "MessageId\n";
		$validationString .= $json->MessageId . "\n";
		if( property_exists( $json, "Subject" ) && $json->Subject != ""){
			$validationString .= "Subject\n";
			$validationString .= $json->Subject . "\n";
		}
		$validationString .= "Timestamp\n";
		$validationString .= $json->Timestamp . "\n";
		$validationString .= "TopicArn\n";
		$validationString .= $json->TopicArn . "\n";
		$validationString .= "Type\n";
		$validationString .= $json->Type . "\n";
	}
	if ( $logToFile ) {
		fwrite( $logFH, "Data Validation String:\n");
		fwrite( $logFH, $validationString . "\n\n");
	}

	$signatureValid = validateCertificate( $json->SigningCertURL, $json->Signature, $validationString );

	if ( !$signatureValid ) {
		$safeToProcess = false;
		if ( $logToFile ) {
			fwrite( $logFH, "Data and Signature Do No Match Certificate or Certificate Error.\n");
		}
	}else{
		if ( $logToFile ) {
			fwrite ( $logFH, "Data Validated Against Certificate.\n\n");
		}
	}
}

if ( $safeToProcess ) {

	//Handle A Subscription Request Programmatically
	if ( $json->Type == "SubscriptionConfirmation"){
		//RESPOND TO SUBSCRIPTION NOTIFICATION BY CALLING THE URL

		if ( $logToFile ) {
			fwrite( $logFH, "Type == SubscriptionConfirmation\n" );
			fwrite( $logFH, $json->SubscribeURL . "\n\n" );
		}

		$curl_handle=curl_init();
		curl_setopt( $curl_handle, CURLOPT_URL, $json->SubscribeURL);
		curl_setopt( $curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
		curl_exec( $curl_handle );
		curl_close( $curl_handle );	
	}


	//Handle a Notification Programmatically
	if ( $json->Type == "Notification"){

		$monitoringFile = "/var/tmp/sns/" . $dateString . "_SNS-incoming-check.txt";
		$monitoringFH = fopen( $monitoringFile, 'a' ) ;
		// Note: If we can't open the monitoring file, that's not fatal. We will still try and 
		// process the incoming message.
		if ( ! $monitoringFH ) {
			if ( $logToFile ) {
				fwrite( $logFH, "Output file for monitoring " . $monitoringFile . " cannot be opened!!!\n" ) ;
			}
		}

		if ( ! property_exists( $json, "Subject" ) ) {
			$json->Subject = "undefined Subject" ;	// If it's not there, create it with a place-holder value.
		}
		
		if ( $logToFile ) {
			fwrite ( $logFH, "Subject: " . $json->Subject . "\n" );
			fwrite ( $logFH, "Message: " . $json->Message . "\n\n" );
		}

		// This is the good stuff!!! (Everything in the JSON other than "Message" is just SNS meta-data.)
		$messageJSON = json_decode( $json->Message ) ;


		// If it's a delivery test SNS message, handle that in a different way.
		// This section will be run if it's a SNS delivery validation / test message coming from 
		// a CloudWatch Event Rule. See https://console.aws.amazon.com/cloudwatch/home?region=us-east-1#rules:
		// for the account "admints".
		if ( property_exists( $messageJSON, "detail-type" ) && $messageJSON->{'detail-type'} == "Scheduled Event" ) {
			if ( property_exists( $messageJSON, "resources" ) && isset( $messageJSON->resources[ 0 ] ) ) {
				$resources0 = $messageJSON->resources[ 0 ] ;
			} else {
				$resources0 = "(no resources[] object)" ;
			}
			if ( $monitoringFH ) {
				fwrite( $monitoringFH, join( ',', array( date( "U" ), $resources0, $messageJSON->{'time'}, $messageJSON->{'detail-type'}, __FILE__ ) ) . "\n" ) ;
				fclose( $monitoringFH ) ;
			}
			if ( $logToFile ) {
				fwrite( $logFH, "\n\$messageJSON->resources[ 0 ]:" . $resources0 . "\n\n" ) ;
				ob_start();
				print_r( $json );
				$output = ob_get_clean();
				fwrite ( $logFH, $output . "\n\n" );
				fclose( $logFH ) ;
			}
			exit( 0 ) ;	// For a Scheduled Event notification, we're done.
		}


		// Go through all the objects we expect to be in the Message.
		// If something is not there, create it with a place-holder value.
		foreach( array( "AlarmName", "AlarmDescription", "NewStateValue", "NewStateReason", "StateChangeTime" ) as $testThis ) {
			if ( ! property_exists( $messageJSON, $testThis ) ) {
				fwrite( $logFH, "Missing object replaced with default value: " . $testThis . "\n" ) ;
				$messageJSON->$testThis = "undefined " . $testThis ;
			}
		}
		if ( ! property_exists( $messageJSON, "Trigger" ) ) {
			$messageJSON->Trigger = "" ;
			if ( ! property_exists( $messageJSON->Trigger, "MetricName" ) ) {
				fwrite( $logFH, "Missing object replaced with default value: Trigger->MetricName\n" ) ;
				$messageJSON->Trigger->MetricName = "undefined Trigger MetricName" ;
			}
		}

		if ( $logToFile ) {
			fwrite( $logFH, "AlarmName: " 		. $messageJSON->AlarmName . "\n" ) ;
			fwrite( $logFH, "AlarmDescription: " 	. $messageJSON->AlarmDescription . "\n" ) ;
			fwrite( $logFH, "Trigger->MetricName: "	. $messageJSON->Trigger->MetricName . "\n\n" ) ;
		}




// Nagios Exit Errorlevels, 0=OK, 1=Warning, 2=Critical, 3=Unknown

		$nagiosStatus = 3 ;					// Set a default.
		if ( $messageJSON->NewStateValue == "ALARM" ) {
			$nagiosStatus = 2 ;
		}
		if ( preg_match( "/HTTPCode_ELB_/i", $messageJSON->Trigger->MetricName ) ) {
			if ( $messageJSON->NewStateValue == "INSUFFICIENT_DATA" ) { 	// This is actually OK because this means
				$nagiosStatus = 0 ;					// there were NONE seen.
			}
			if ( $messageJSON->NewStateValue == "OK" ) {			// This is actually a Warning because
				$nagiosStatus = 1 ;					// this means there were SOME seen,
			}								// it just didn't go over the threshold.
		} else {
			if ( $messageJSON->NewStateValue == "INSUFFICIENT_DATA" ) {
				$nagiosStatus = 1 ;
			}
			if ( $messageJSON->NewStateValue == "OK" ) {
				$nagiosStatus = 0 ;
			}
		}


// Working from shell:
// echo "[`date +%s`] PROCESS_SERVICE_CHECK_RESULT;kwp490-ELB-Alarm;HealthyHostCount;0;Foo bar baz" > ~nagios/var/rw/nagios.cmd
// echo "[`date +%s`] PROCESS_HOST_CHECK_RESULT;kwp490-ELB-Alarm;0;Foink" > ~nagios/var/rw/nagios.cmd

		$alarmNameExploded = explode( " ", $messageJSON->AlarmName, 2 ) ;
		$webSiteName = $alarmNameExploded[ 0 ] ;
		$webSiteName = str_replace( "-", ".", $webSiteName ) ;
		$nagiosHostName = $webSiteName . ":" . $messageJSON->Trigger->Dimensions[ 0 ]->value ;
		$nagiosServiceName = $messageJSON->Trigger->MetricName . ": " . $messageJSON->AlarmName ;	// Updated 2015-08-28 to match the new format from ~nagios/libexec/FAS/Nagios-config-from-alarms.php line 452 -- Stefan

		$nagiosStatusInfo = $alarmNameExploded[ 1 ]
				. ": "
				. $messageJSON->NewStateReason 
				. " "
				. $messageJSON->StateChangeTime ;

		$nagiosMessage = "[" . date( "U" ) . "] PROCESS_SERVICE_CHECK_RESULT;" 
				. $nagiosHostName . ";"
				. $nagiosServiceName . ";"
				. $nagiosStatus . ";"
				. $nagiosStatusInfo ;

		if ( $logToFile ) {
			fwrite( $logFH, "Nagios message:\n" . $nagiosMessage . "\n" ) ;
		}

		$pipePath = "/usr/local/nagios/var/rw/nagios.cmd" ;
		if ( ! file_exists( $pipePath ) ) {
			echo "Error - can't find Nagios command pipe!!!\n" ;
			if ( $logToFile ) {
				fwrite( $logFH, "Error - can't find Nagios command pipe " . $pipePath . " !!!\n" ) ;
				fclose( $logFH ) ;
			}
			exit( 1 ) ;
		}

		$nagiosCommandPipe = fopen( $pipePath, "a" ) ;
		if ( ! $nagiosCommandPipe ) {
			echo "Error - can't open Nagios command pipe!!!\n" ;
			if ( $logToFile ) {
				fwrite( $logFH, "Error opening " . $pipePath . " !!!\n" ) ;
				fclose( $logFH ) ;
			}
			exit( 1 ) ;
		}
		if ( $writeToNagios ) {
			fwrite( $nagiosCommandPipe, $nagiosMessage . "\n" ) ;
			if ( $logToFile ) {
				fwrite( $logFH, "Written to " . $pipePath . "\n\n" ) ;
			}
		} else {
			fwrite( $logFH, "*** DEBUG ON - NOT written to " . $pipePath . " ***\n\n" ) ;
		}
		fclose( $nagiosCommandPipe ) ;

		if ( $logToFile ) {
			fwrite( $logFH, "Finished processing Notification!\n" ) ;
		}
	}
}

//Clean Up For Debugging.
if ( $logToFile ) {

	ob_start();
	print_r( $json );
	$output = ob_get_clean();
	fwrite ( $logFH, $output . "\n\n" );
	echo $output . "\n" ;


   if ( ! is_null( $messageJSON ) && $messageJSON != "" ) {
	ob_start();
	print_r( $messageJSON );
	$output = ob_get_clean();
	fwrite ( $logFH, $output . "\n\n" );
	echo $output . "\n" ;
   }

	////WRITE LOG
	fclose( $logFH );
}


// -----------------------------------------------------------------------------------------------------------
// A Function that takes the key file, signature, and signed data and tells us if it all matches.
function validateCertificate( $keyFileURL, $signatureString, $data ) {

	$signature = base64_decode( $signatureString );


	// fetch certificate from file and ready it
	$fp = fopen($keyFileURL, "r");
	$cert = fread($fp, 8192);
	fclose($fp);

	$pubkeyid = openssl_get_publickey( $cert );

	$ok = openssl_verify( $data, $signature, $pubkeyid, OPENSSL_ALGO_SHA1 );

	if ($ok == 1) {
	    return true;
	} elseif ($ok == 0) {
	    return false;
	} else {
	    return false;
	}	
}


// -----------------------------------------------------------------------------------------------------------
//A Function that takes a URL String and returns the domain portion only
function getDomainFromUrl($urlString){
	$domain = "";
	$urlArray = parse_url($urlString);

	if($urlArray == false){
		$domain = "ERROR";
	}else{
		$domain = $urlArray['host'];
	}

	return $domain;
}
// -----------------------------------------------------------------------------------------------------------



?>
