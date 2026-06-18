<?php

//Import PHPMailer classes into the global namespace
//These must be at the top of your script, not inside a function
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as exc;
//Load Composer's autoloader
require 'vendor/autoload.php';
//Instantiation and passing `true` enables exceptions
$mail = new PHPMailer(true);
$mail->SMTPDebug = 0;
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;
$mail->Username = 'educationnderaresearch@gmail.com'; // Zimbra email account    
$mail->Password = 'dtfy aqbn jxff iclu'; // Zimbra account password
$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
$mail->Port = 465;
$mail->CharSet = 'UTF-8';
$mail->Encoding = 'base64';
// Recipients
setFrom('educationnderaresearch@gmail.com', 'NNPTH APPLICATION');
function setFrom($from = "educationnderaresearch@gmail.com",$name="NNPTH")
{
    // global $mail;
    // $mail->setFrom($from, "NNPTH");
     global $mail;
    $mail->setFrom($from, $name);
    $mail->addReplyTo($from, $name); // Add reply-to
    $mail->Sender = $from; // Add sender envelope
}
function addRecipients($res = array())
{
    global $mail;
    $len = count($res);
    if ($len > 0) {
        for ($i = 0; $i < $len; $i++) {
            $mail->addAddress($res[$i]); //Add a recipient
        }
    }
}
function addRecipientWithCC($res = array())
{
    global $mail;
    $len = count($res);
    if ($len > 0) {
        for ($i = 0; $i < $len; $i++) {
            $mail->addCC($res[$i]); //Add a recipient with cc
        }
    }
}
function addRecipientWithBCC($res = array())
{
    global $mail;
    $len = count($res);
    if ($len > 0) {
        for ($i = 0; $i < $len; $i++) {
            $mail->addBCC($res[$i]); //Add a recipient with cc
        }
    }
}
function addAttachment($paths = array())
{
    global $mail;
    $len = count($paths);
    if ($len > 0) {
        for ($i = 0; $i < $len; $i++) {
            $mail->addAttachment($paths[$i]); //Add a recipient with cc
        }
    }
    // $mail->addAttachment($path); //Add attachments
    // $mail->addAttachment('/tmp/image.jpg', 'new.jpg');    //Optional name
}
function send($isHtml = false, $subject = 'NNPTH email testing', $body = "welcome to NNPTH Application", $altbody = "NNPTH ")
{
    global $mail;
    $mail->Priority = 3;
    $mail->Subject = $subject;
    $mail->AltBody = $altbody;
    if ($isHtml) {
        $mail->isHTML(true);
        $mail->Body = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($subject) . '</title></head><body>' . $body . '</body></html>';
    } else {
        $mail->Body = $body;
    }
    $mail->send();
    return "Message has been sent";
}
function hasError()
{
    global $mail;
    if (strlen($mail->ErrorInfo) > 0) return true;
    return false;
}
function getErrors()
{
    global $mail;
    return strlen($mail->ErrorInfo) > 0 ? "Message could not be sent. Mailer Error: {$mail->ErrorInfo}" : "";
}
// order for how to use this functions
// 1.change setting according to your needs at line 10-15
//2.setFrom($resp:string)
//3.addRecipient(resp:array)
//4.addAttachment($filePath:string)
//5.send($sub:string,body:string,altbody:string,isHtml=false)
//6.getErrors():string
// make test

// try {
//      // 'amudala41@gmail.com', 'hategasam@gmail.com'
//     setFrom();
//     addRecipients(array('askforgilbert@gmail.com'));
//     send(false,"EMail Testing", "NNPTH main testing");
// } catch (Exception $e) {
//     echo getErrors();
// }
