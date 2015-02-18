<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require("params.php");

$init= false;
if (isset($_GET['state'])) {
	if (strtolower($_GET['state']) == "init") {
		$init= true;
		getAccessCode();
		@unlink("./data/" . $wrike_oauth2_id . ".access.token");
		@unlink("./data/" . $wrike_oauth2_id . ".access.expires");
	}
}

if (!$init) {
	$access_token= false;
	if (file_exists("./data/" . $wrike_oauth2_id . ".access.token")) {
		$access_token= file_get_contents("./data/" . $wrike_oauth2_id . ".access.token");
	}

	$expires= false;
	if (file_exists("./data/" . $wrike_oauth2_id . ".access.expires")) {
		$expires= file_get_contents("./data/" . $wrike_oauth2_id . ".access.expires")*1;
	}

	if (!$access_token || (time() > $expires)) {
		if (isset($_GET['code'])) {
			getAccessToken($_GET['code']);
		} else {
			if (file_exists("./data/" . $wrike_oauth2_id . ".refresh.token")) {
				getRefreshToken();
			} else {
				getAccessCode();
			}
		}
	} else {
		if (isset($_GET['state'])) {
			if (strtolower($_GET['state']) == "getusers") {
				getContacts($access_token);
			}
		}

		$users= array();
		if (file_exists("./data/" . $wrike_oauth2_id . ".users.data")) {
			$temp= file_get_contents("./data/" . $wrike_oauth2_id . ".users.data");
			$temp= explode("\n", $temp);
			foreach ($temp as $user) {
				if ($pos= strpos($user, "|")) {
					$users[]= array(
						"id" => substr($user,0,$pos),
						"email" => strtolower(substr($user,$pos+1)),
					);
				}
			}
		}

		$body= "";
		if ($f= @fopen('php://input', 'r')) {
			while (!feof($f)) {
				$s= fread($f, 1024);
				if (is_string($s)) {
					$body.= $s;
				}
			}
			fclose($f);
		}

		if (trim($body) != "") {
			$github= json_decode($body, true);
			$wrikeResponses= array();

			fileLog("github", json_encode($github, JSON_PRETTY_PRINT));
			if (isset($github['commits'])) {
				foreach ($github['commits'] as $commit) {
					$comments= $commit['message'];
					$comments= str_replace("\\n", "\n", $comments);
					$pos= strpos($comments,"\n\n");
					$title= trim(substr($comments,0,$pos));
					$comments= explode("\n", trim(substr($comments,$pos+2)));

					if ($task_id= getTaskId($access_token, $title)) {
						$text = "===================================================================<br>";
						$text.= "Source Code Committed<br>";
						$text.= "URL: " . $commit['url'] . "<br>";
						$text.= "User: " . $commit['committer']['email'] . "<br>";
						$text.= "<br>";
						$text.= "Modification:<br>";
						foreach ($commit['added'] as $added) {
							$text.= " - [ADDED] " . $added . "<br>";
						}
						foreach ($commit['removed'] as $removed) {
							$text.= " - [REMOVED] " . $removed . "<br>";
						}
						foreach ($commit['modified'] as $modified) {
							$text.= " - [MODIFIED] " . $modified . "<br>";
						}
						$text.= "<br>";
						$text.= "Comment:<br>";
						foreach ($comments as $c) {
							$text.= " " . $c . "<br>";
						}

						addComment($access_token, $task_id, $text);
					}
				}
			}
		}
	}
}

function fileLog($filename, $action) {
	if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) $source= $_SERVER['HTTP_X_FORWARDED_FOR']; else $source= $_SERVER['REMOTE_ADDR'];

	$filename= "logs/" . date('Ymd.H') . "-" . $filename . ".log";
	if ($log_handle= fopen($filename, 'a')) {
		$action= explode("\n", $action);
		fwrite($log_handle, date('H:i:s') . "~" . $source . ":\n");
		for ($i= 0; $i < count($action); $i++) {
			fwrite($log_handle, $action[$i] . "\n");
		}
		fclose($log_handle);
	}
	return true;
}

function getAccessCode() {
	GLOBAL $script_url, $wrike_oauth2_url, $wrike_oauth2_id, $wrike_oauth2_secret;
	$param= array(
		"client_id" => $wrike_oauth2_id,
		"response_type" => "code",
		"redirect_uri" => $script_url,
	);
	header("Location: " . $wrike_oauth2_url . "/authorize?" . http_build_query($param));
}

function getAccessToken($access_code) {
	GLOBAL $script_url, $wrike_oauth2_url, $wrike_oauth2_id, $wrike_oauth2_secret;

	$param= Array(
		"client_id" => $wrike_oauth2_id,
		"client_secret" => $wrike_oauth2_secret,
		"grant_type" => "authorization_code",
		"code" => $access_code,
		"redirect_uri" => $script_url,
	);
	$url= $wrike_oauth2_url . "/token";

	fileLog("wrike", "request", array("url" => $url, "param" => $param));

	$ch= curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($param));
	$result = curl_exec($ch);
	curl_close($ch);
	$result= json_decode($result, true);

	fileLog("wrike", "response", $result);

	if (isset($result["access_token"]) && isset($result["refresh_token"])) {
		if ($f= fopen("./data/" . $wrike_oauth2_id . ".access.token", "w")) {
			fwrite($f, $result["access_token"]);
			fclose($f);
		}

		if ($f= fopen("./data/" . $wrike_oauth2_id . ".access.expires", "w")) {
			fwrite($f, (time() + $result['expires_in'] - 300));
			fclose($f);
		}

		if ($f= fopen("./data/" . $wrike_oauth2_id . ".refresh.token", "w")) {
			fwrite($f, $result["refresh_token"]);
			fclose($f);
		}
	}
}

function getRefreshToken() {
	GLOBAL $script_url, $wrike_oauth2_url, $wrike_oauth2_id, $wrike_oauth2_secret;

	if (file_exists("./data/" . $wrike_oauth2_id . ".refresh.token")) {
		//Old refresh token exists, use refresh token
		$use_refresh_token= true;
		$refresh_token= file_get_contents("./data/" . $wrike_oauth2_id . ".refresh.token");

		$param= array(
			"client_id" => $wrike_oauth2_id,
			"client_secret" => $wrike_oauth2_secret,
			"grant_type" => "refresh_token",
			"refresh_token" => $refresh_token,
			"redirect_uri" => $script_url,
		);
		$url= $wrike_oauth2_url . "/token";

		fileLog("wrike", "request", array("url" => $url, "param" => $param));

		$ch= curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($param));
		$result = curl_exec($ch);
		curl_close($ch);
		$result= json_decode($result, true);

		fileLog("wrike", "response", $result);

		if (isset($result["access_token"]) && isset($result["refresh_token"])) {
			if ($f= fopen("./data/" . $wrike_oauth2_id . ".access.token", "w")) {
				fwrite($f, $result["access_token"]);
				fclose($f);
			}

			if ($f= fopen("./data/" . $wrike_oauth2_id . ".access.expires", "w")) {
				fwrite($f, (time() + $result['expires_in'] - 300));
				fclose($f);
			}

			if ($f= fopen("./data/" . $wrike_oauth2_id . ".refresh.token", "w")) {
				fwrite($f, $result["refresh_token"]);
				fclose($f);
			}
		} else {
			getAccessCode();
		}
	}
}

function getUsers($access_token) {
	GLOBAL $wrike_api_url, $wrike_oauth2_id;

	$param= array(
		"fields" => "[\"metadata\"]",
	);
	$url= $wrike_api_url . "/contacts?" . http_build_query($param);

	fileLog("wrike", "request", array("url" => $url, "param" => $param));

	$ch= curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: bearer " . $access_token));
	$result = curl_exec($ch);
	curl_close($ch);
	$result= json_decode($result, true);

	fileLog("wrike", "response", $result);

	$retVal= array();
	if (isset($result['data'][0]['id'])) {
		if ($f= fopen("./data/" . $wrike_oauth2_id . ".users.data", "w")) {
			foreach ($result['data'] as $data) {
				if ($data['type'] == "Person") {
					$id= $data['id'];
					$email= $data['profiles'][0]['email'];

					$retVal[]= array(
						"id" => $id,
						"email" => $email,
					);

					fwrite($f, $id . "|" . $email . "\n");
				}
			}
			fclose($f);
		}
	}
	return $retVal;
}

function getTaskId($access_token, $permalink) {
	GLOBAL $wrike_api_url;

	$param= array(
		"permalink" => $permalink,
	);
	$url= $wrike_api_url . "/tasks?" . http_build_query($param);

	fileLog("wrike", "request", array("url" => $url, "param" => $param));

	$ch= curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: bearer " . $access_token));
	$result = curl_exec($ch);
	curl_close($ch);
	$result= json_decode($result, true);

	fileLog("wrike", "response", $result);

	$retVal= false;
	if (isset($result['data'][0]['id'])) {
		$retVal= $result['data'][0]['id'];
	}
	return $retVal;
}

function addComment($access_token, $task_id, $comment) {
	GLOBAL $wrike_api_url;

	$param= array(
		"text" => $comment,
	);
	$url= $wrike_api_url . "/tasks/" . $task_id . "/comments";

	fileLog("wrike", "request", array("url" => $url, "param" => $param));

	$ch= curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: bearer " . $access_token));
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($param));
	$result = curl_exec($ch);
	curl_close($ch);
	$result= json_decode($result, true);

	fileLog("wrike", "response", $result);

	return $result;
}
?>