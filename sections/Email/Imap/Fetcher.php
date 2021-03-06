<?php

namespace Iris\Config\CRM\sections\Email\Imap;

use Config;
use Iris\Config\CRM\sections\Email\EmailException;
use Iris\Config\CRM\sections\Email\Fetcher\FetcherInterface;
use Iris\Iris;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Fetch emails using IMAP
 * Class Fetcher
 * @package sections\Email\Imap
 */
class Fetcher extends Config implements FetcherInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    private $supportEmails = [];

    const MAX_INCIDENT_BODY_LENGTH = 1000;

    function __construct()
    {
        parent::__construct([
            'common/Lib/lib.php',
            'common/Lib/access.php',
        ]);

        $this->logger = Iris::$app->getContainer()->get('logger.factory')->get('imap');
    }

    // TODO: copied from EmailFetcher class
    protected function getSupportEmails() {
        $result = array();

        $res = $this->connection->query("select stringvalue as value from iris_systemvariable
          where code='support_email_addresses'")->fetchAll(PDO::FETCH_ASSOC);
        $emails = $res[0]['value'];
        if ($emails != '') {
            $emails = iris_str_replace(' ', '', $emails);
            $result = explode(',', $emails);
        }

        return $result;
    }

    public function addMimeMessageToMailbox($emailAccountId, $mailboxName, $MimeMessage)
    {
        $emailAccounts = $this->getEmailAccounts($emailAccountId);
        $adapter = $this->getImapAdapter($emailAccounts[0]);
        return $adapter->addMimeMessageToMailbox($mailboxName, $MimeMessage);
    }

    public function markMailAsRead($emailAccountId, $mailboxName, $uid)
    {
        $emailAccounts = $this->getEmailAccounts($emailAccountId);
        $adapter = $this->getImapAdapter($emailAccounts[0]);
        $adapter->selectMailbox($mailboxName);

        return $adapter->markMailAsRead($uid);
    }

    public function triggerMailImportantState($emailAccountId, $mailboxName, $uid, $isImportant)
    {
        $emailAccounts = $this->getEmailAccounts($emailAccountId);
        $adapter = $this->getImapAdapter($emailAccounts[0]);
        $adapter->selectMailbox($mailboxName);

        return $adapter->triggerMailImportantState($uid, $isImportant);
    }

    public function deleteMail($emailAccountId, $mailboxName, $uid)
    {
        $emailAccounts = $this->getEmailAccounts($emailAccountId);
        $adapter = $this->getImapAdapter($emailAccounts[0]);
        $adapter->selectMailbox($mailboxName);

        return $adapter->deleteMail($uid);
    }

    public function getEmailAccountStatus($emailAccountId) {
        $result = [];
        $emailAccounts = $this->getEmailAccounts($emailAccountId, true);

        $imapAdapter = $this->getImapAdapter($emailAccounts[0]);

        return $imapAdapter->getMailboxesStatus();
    }

    public function syncFlags($emailAccountId = null)
    {
        $emailAccounts = $this->getEmailAccounts($emailAccountId);
        foreach ($emailAccounts as $emailAccount) {
            $mailboxes = $this->getMailboxes($emailAccount);
            if (count($mailboxes) === 0) {
                continue;
            }

            $imapAdapter = $this->getImapAdapter($emailAccount);
            foreach ($mailboxes as $mailbox) {
                $this->syncMailboxFlags($imapAdapter, $mailbox);
            }
        }
    }

    protected function syncMailboxFlags(ImapAdapter $imapAdapter, $mailbox)
    {
        $this->debug("syncMailboxFlags emailAccountId", $mailbox);
        $dbOverviews = $this->getEmailsOverviewFromDB($mailbox['id']);
        $dbOverviewLookup = $this->createOverviewLookup($dbOverviews);
        $serverOverviews = $this->getEmailsOverviewFromServer($imapAdapter, $mailbox["name"]);

        foreach($serverOverviews as $serverOverview) {
            if (!array_key_exists($serverOverview["uid"], $dbOverviewLookup)) {
                continue; // email not exists in DB (deleted)
            }
            $dbOverview = $dbOverviewLookup[$serverOverview["uid"]];
            if ($this->isFlagsEqual($dbOverview, $serverOverview)) {
                continue;
            }

            $this->debug("syncMailboxFlags sync flags for", $serverOverview["uid"]);
            $this->updateFlags($dbOverview, $serverOverview);
        }
        $this->debug("syncMailboxFlags completed");
    }

    protected function getEmailsOverviewFromDB($mailboxId)
    {
        $sql = "select T0.id, T0.uid, T0.has_readed, EA.ownerid,
          case when strpos(T0.has_readed, EA.ownerid) > 0 then 1 else 0 end as seen, isimportant
          from iris_email T0
          left join iris_emailaccount_mailbox MB on T0.mailboxid = MB.id
          left join iris_emailaccount EA on MB.emailaccountid = EA.id
            where T0.mailboxid= :mailboxid
            order by uid";
        $cmd = $this->connection->prepare($sql);
        $cmd->execute(array(
            ":mailboxid" => $mailboxId,
        ));

        return $cmd->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function createOverviewLookup($dbOverviews)
    {
        $result = array();

        foreach ($dbOverviews as $dbOverview) {
            $result[$dbOverview["uid"]] = $dbOverview;
        }

        return $result;
    }

    protected function getEmailsOverviewFromServer(ImapAdapter $imapAdapter, $mailboxName)
    {
        return $imapAdapter->getEmailsOverview($mailboxName);
    }

    protected function isFlagsEqual($dbOverview, $serverOverview)
    {
        return ($dbOverview["seen"] == $serverOverview["seen"]) and
            ($dbOverview["isimportant"] == $serverOverview["flagged"]);
    }

    protected function updateFlags($dbOverview, $serverOverview)
    {
        $hasReadedStr = $this->updateHasReadedStr($dbOverview["has_readed"], $dbOverview["ownerid"], $serverOverview["seen"]);
        $this->debug("updateFlags hasReadedStr", array($hasReadedStr, $dbOverview["has_readed"], $dbOverview["ownerid"], $serverOverview["seen"]));

        $sql = "update iris_email set has_readed = :hasreaded, isimportant = :isimportant where id = :id";
        $cmd = $this->connection->prepare($sql);
        $cmd->execute(array(
            ":hasreaded" =>$hasReadedStr,
            ":isimportant" => $serverOverview["flagged"],
            ":id" => $dbOverview["id"],
        ));
    }

    protected function updateHasReadedStr($hasReadedStr, $ownerId, $isSeen)
    {
        $readedIds = json_decode($hasReadedStr, true);

        if (!is_array($readedIds)) {
            $readedIds = array();
        }

        if ($isSeen and !in_array($ownerId, $readedIds)) {
            $readedIds[] = $ownerId;
        }

        if (!$isSeen and in_array($ownerId, $readedIds)) {
            array_splice($readedIds, array_search($ownerId, $readedIds), 1);
        }

        return count($readedIds) == 0 ? null : json_encode($readedIds);
    }

    /**
     * Fetch new email
     * @param guid $emailAccountId
     * @param int $batchSize Maximum amount of messages to receive per one request
     */
    public function fetch($emailAccountId = null, $batchSize = 10)
    {
        $result = array(
            "isSuccess" => true,
            "messagesCount" => 0,
        );

        $this->supportEmails = $this->getSupportEmails();

        $this->debug("---------------------------------------------------------------------------------------");
        $this->debug("fetch emailAccountId", $emailAccountId);
        $emailAccounts = $this->getEmailAccounts($emailAccountId);
        foreach ($emailAccounts as $emailAccount) {
            $this->debug("emailAccount", $emailAccount["login"]);

            $mailboxes = $this->getMailboxes($emailAccount);
            if (count($mailboxes) === 0) {
                $this->debug("no mailboxes for sync");
                continue;
            }

            // $imapAdapter = $this->getImapAdapter($emailAccount);
            foreach ($mailboxes as $mailbox) {
                // init imapAdapter in loop for correct work if mailboxes length > 1
                $imapAdapter = $this->getImapAdapter($emailAccount);
                $this->debug("mailbox", $mailbox);
                $this->debug("mailbox lastuid (DB)", $mailbox["lastuid"]);

                $fetchResult = $this->fetchMailbox($imapAdapter, $mailbox, $mailbox["lastuid"] + 1,
                    $batchSize - $result["messagesCount"]);
                $result["isSuccess"] = $result["isSuccess"] && $fetchResult["isSuccess"];
                $result["messagesCount"] = $result["messagesCount"] + $fetchResult["messagesCount"];
                if ($result["messagesCount"] >= $batchSize) {
                    break;
                }
            }
        }

        return $result;
    }

    protected function getEmailAccounts($emailAccountId = null, $isGetAll = false)
    {
        $con = $this->connection;

        $sql = "select id, address as server, port,
                case when encryption <> 'no' then encryption else null end as protocol, login, password
                from iris_emailaccount 
                where (isactive='Y' or :getall = 1) and fetch_protocol = 2 and (id = :emailaccountid or :emailaccountid is null)";

        $cmd = $con->prepare($sql);
        $cmd->execute(array(":getall" => $isGetAll ? 1 : 0,
            ":emailaccountid" => $emailAccountId));

        return $cmd->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getMailboxes($emailAccount)
    {
        $con = $this->connection;

        $sql = "select T0.id, T0.name, T0.lastuid, T1.ownerid as emailaccountownerid,
          T0.emailaccountid as emailaccountid, T0.emailtypeid as emailtypeid
          from iris_emailaccount_mailbox T0
          left join iris_emailaccount T1 on T0.emailaccountid = T1.id
          where emailaccountid = :emailaccountid and issync = 1";

        $cmd = $con->prepare($sql);
        $cmd->execute(array(":emailaccountid" => $emailAccount["id"]));

        return $cmd->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getImapAdapter($emailAccount)
    {
        return new ImapAdapter($emailAccount["server"], $emailAccount["port"], $emailAccount["protocol"],
            $emailAccount["login"], $emailAccount["password"]);
    }

    protected function fetchMailbox(ImapAdapter $imapAdapter, $mailbox, $fetchFromUid, $batchSize)
    {
        $imapAdapter->selectMailbox($mailbox["name"]);
        $this->debug("fetchMailbox selectMailbox", $mailbox["name"]);
        $emails = $imapAdapter->getEmailsFromUid($fetchFromUid, $batchSize);
        $this->debug("getEmailsFromUid count", count($emails));
        $messagesCount = 0;

        foreach ($emails as $email) {
            $this->debug("fetch email", array($email["uid"], $email["from"], $email["subject"]));
            try {
                if (!$this->isNewEmail($mailbox["id"], $email["uid"])) {
                    $this->debug("isNewEmail false");
                    throw new EmailException('Just continue');
                }

                $this->saveEmail($mailbox, $email);
                $messagesCount++;
            }
            catch (EmailException $e) {
                // Just continue
            }

            $this->updateLastFetchUid($mailbox["id"], $email["uid"]);
        }

        return array(
            "isSuccess" => is_array($emails),
            "messagesCount" => $messagesCount,
        );
    }

    protected function isNewEmail($mailboxId, $uid)
    {
        $cmd = $this->connection->prepare("select id from iris_email where mailboxid = :mailboxid and uid = :uid");
        $cmd->execute(array(
            ":mailboxid" => $mailboxId,
            ":uid" => $uid,
        ));
        $data = $cmd->fetchAll(PDO::FETCH_ASSOC);

        return count($data) < 1;
    }

    protected function saveEmail($mailbox, $email)
    {
        list ($accountId, $contactId, $ownerId, $incidentId) = $this->getEmailLinks($email["from"], $email["subject"]);

         if (!$incidentId && $this->isSupportEmail($email["to"])) {
             list($incidentId, $incidentNumber) = $this->insertIncident(
                 $mailbox["id"], $email["subject"], $email["body"], $accountId, $contactId, $ownerId);
             $email["subject"] = $this->addIncidentNumberToSubject($incidentNumber, $email["subject"]);
         }

        $attachments = $this->addFileIdToAttachments($email["attachments"]);

        $body = $this->replaceInlineLinksInBody($email["body"], $email["cidPlaceholders"], $attachments);

        $this->debug("saveEmail subject", $email["subject"]);
        $this->debug("saveEmail attachments", $attachments);
        $this->debug("saveEmail cidPlaceholders", $email["cidPlaceholders"]);

        $emailId = $this->insertEmail($email, $body, $mailbox["emailaccountid"],
            $mailbox["id"], $mailbox["emailaccountownerid"],
            $mailbox["emailtypeid"], $accountId, $contactId, $ownerId,
            $incidentId);

        $this->insertAttachments($emailId, $mailbox["id"], $accountId, $contactId, $ownerId, $incidentId, $attachments);
    }

    protected function getEmailLinks($from, $subject)
    {
        // iris_contact_email, iris_account_email has lowered emails (see trigger function)
        $sql = "select id as contactid, accountid, ownerid
        from iris_contact
        where lower(email)=lower(:email)
          or id in (
            select contactid from iris_contact_email
                where email = lower(:email))
        union
        select null as contactid, id as accountid, ownerid
        from iris_account
        where lower(email)=lower(:email)
          or id in (
            select accountid from iris_account_email
                where email = lower(:email))";
        $cmd = $this->connection->prepare($sql);
        $cmd->execute(array(
            ":email" => $from,
        ));
        $data = current($cmd->fetchAll(pdo::FETCH_ASSOC));

        return array($data["accountid"], $data["contactid"], $data["ownerid"], $this->getIncidentId($subject));
    }

    protected function getIncidentId($subject)
    {
        $matches = array();
        $isFind = mb_ereg("\\[\\d{6}-\\d+\\]", $subject, $matches);

        $this->debug("getIncidentId match", array($subject, $matches));
        if (!$isFind) {
            return null;
        }

        $incidentNumber = trim($matches[0], "\x5B..\x5D"); // обрезаем скобки [ и ]
        $cmd = $this->connection->prepare("select id as id from iris_incident where number = :number");
        $cmd->execute(array(":number" => $incidentNumber));
        $data = current($cmd->fetchAll(PDO::FETCH_ASSOC));

        return $data["id"];
    }

    protected function isSupportEmail($address)
    {
        // address: John Doe <john@example.com>, support@example.com
        // supportEmails: array(support@example.com, help@example.com)
        $this->debug("isSupportEmail match", array($this->supportEmails, $address));
        foreach ($this->supportEmails as $supportEmail) {
            if (strpos($address, $supportEmail) !== false) {
                return true;
            }
        }

        return false;
    }

    // TODO: use method in EmailFetcher
    protected function insertIncident($mailboxId, $subject, $body, $accountId, $contactId, $ownerId)
    {
        $incidentNumber = GenerateNewNumber('IncidentNumber', null, $this->connection);
        $incidentId = create_guid();

        // сформируем текстовое содержимое письма, которое не превышает 1000 символов
        $shortBody = $body;
        $shortBody = iris_str_replace(chr(13).chr(10), '', $shortBody);
        $shortBody = iris_str_replace(chr(10).chr(13), '', $shortBody);
        $shortBody = iris_str_replace('<br>', chr(10), $shortBody);
        $shortBody = iris_str_replace('<BR>', chr(10), $shortBody);
        $shortBody = strip_tags($shortBody);
        if (iris_strlen($shortBody) >= self::MAX_INCIDENT_BODY_LENGTH) {
            $shortBody = iris_substr($shortBody, 0, self::MAX_INCIDENT_BODY_LENGTH);
        }

        $sql = "insert into iris_incident (id, number, name, description, accountid, contactid, ownerid, date,
          incidentstateid, isremind, reminddate) values (:id, :number, :name, :description, :accountid,
          :contactid, :ownerid, now(), (select id from iris_incidentstate where code='Plan'), 1, now())";
        $cmd = $this->connection->prepare($sql);
        $cmd->execute(array(
            ":id" => $incidentId,
            ":number" => $incidentNumber,
            ":name" => $subject,
            ":description" => $shortBody,
            ":accountid" => $accountId,
            ":contactid" => $contactId,
            ":ownerid" => $ownerId
        ));

        if ($cmd->errorCode() != '00000') {
            $this->logger->error('Cant insert incident ' . $incidentNumber, $cmd->errorInfo());
            throw new EmailException('Cant insert incident');
        }

        // увеличим номер инцидента
        UpdateNumber('Incident', $incidentId, 'IncidentNumber');

        $this->insertAccess("iris_incident", $incidentId, $mailboxId);

        return array($incidentId, $incidentNumber);
    }

    protected function addIncidentNumberToSubject($incidentNumber, $subject)
    {
        return "[" . $incidentNumber . "] " . $subject;
    }

    protected function addFileIdToAttachments($attachments)
    {
        $result = array();
        $buffer = null;
        foreach ($attachments as $attachment) {
            $buffer = $attachment;
            $buffer["fileId"] = create_guid();
            $result[] = $buffer;
        }

        return $result;
    }

    protected function replaceInlineLinksInBody($body, $cidPlaceholders, $attachments)
    {
        $result = $body;

        foreach ($attachments as $attachment) {
            if (!array_key_exists($attachment["contentId"], $cidPlaceholders)) {
                continue;
            }

            $placeholder = $cidPlaceholders[$attachment["contentId"]];
            $replaceTo = "web.php?_func=DownloadFile&table=iris_File&id=".$attachment["fileId"]."&column=file_file";
            $result = str_replace($placeholder, $replaceTo, $result);
        }

        return $result;
    }

    protected function insertEmail($email, $body, $emailAccountId, $mailboxId, $emailAccountOwnerId, $emailTypeID, $accountId, $contactId, $ownerId, $incidentId)
    {
        $emailId = create_guid();
        $readedStr = $email["seen"] ? json_encode(array($emailAccountOwnerId)) : null;

        $sql = "insert into iris_email(id, createid, createdate, uid, e_from, e_to, e_cc, e_bcc, subject, body, emailtypeid,
            emailaccountid, mailboxid, accountid, contactid, ownerid, messagedate, incidentid, isimportant, has_readed) 
            values (:id, :createid, now(), :uid, :e_from, :e_to, :e_cc, :e_bcc, :subject, :body,
            :emailtypeid, :emailaccountid, :mailboxid,
            :accountid, :contactid, :ownerid, to_timestamp(:messagedate, 'DD.MM.YYYY HH24:MI:SS'), :incidentid,
            :isimportant, :readedstr)";

        $cmd = $this->connection->prepare($sql);
        $emailParams = [
            ":id" => $emailId,
            ":createid" => $this->_User ? $this->_User->property('id') : null,
            ":uid" => $email["uid"],
            ":e_from" => $email["from"],
            ":e_to" => $email["to"],
            ":e_cc" => $email["cc"],
            ":e_bcc" => $email["bcc"],
            ":subject" => $email["subject"],
            ":body" => $body,
            ":emailtypeid" => $emailTypeID,
            ":accountid" => $accountId,
            ":emailaccountid" => $emailAccountId,
            ":mailboxid" => $mailboxId,
            ":contactid" => $contactId,
            ":ownerid" => $ownerId,
            ":messagedate" => $email["date"],
            ":incidentid" => $incidentId,
            ":isimportant" => $email["flagged"],
            ":readedstr" => $readedStr,
        ];
        $cmd->execute($emailParams);

        if ($cmd->errorCode() != '00000') {
            // Из-за кривой кодировки не все попадает в лог, поэтому тут столько сообщений
            $this->logger->error('Cant insert email', $cmd->errorInfo());
            $this->logger->error('Cant insert email with next body', [ $emailParams[':body'] ]);
            unset($emailParams[':body']);
            $this->logger->error('Cant insert email (other params)', $emailParams);
            throw new EmailException('Email is not inserted: ' . var_export($cmd->errorInfo(), true));
        }

        $this->insertAccess("iris_email", $emailId, $mailboxId);

        return $emailId;
    }

    protected function insertAttachments($emailId, $mailboxId, $accountId, $contactId, $ownerId, $incidentId, $attachments)
    {
        $storage = Iris::$app->getContainer()->get('storage.service');
        foreach ($attachments as $attachment) {
            $this->insertAttachment($storage, $emailId, $mailboxId, $accountId, $contactId, $ownerId, $incidentId, $attachment);
        }
    }

    protected function insertAttachment($storage, $emailId, $mailboxId, $accountId, $contactId, $ownerId, $incidentId, $attachment)
    {
        $fileRealName = create_guid();

        $sql = "insert into iris_file (id, createdate, file_file, file_filename, 
            emailId, accountId, contactId, ownerId, incidentId, fileStateId, date) values 
            (:id, now(), :file_file, :file_filename, :emailid, :accountid, 
            :contactid, :ownerid, :incidentid, (select id from iris_filestate where code = 'Active'), now())";
        $cmd = $this->connection->prepare($sql);

        $cmd->execute(array(
            ":id" => $attachment["fileId"],
            ":file_file" => $fileRealName,
            ":file_filename" => $attachment["fileName"],
            ":emailid" => $emailId,
            ":accountid" => $accountId,
            ":contactid" => $contactId,
            ":ownerid" => $ownerId,
            ":incidentid" => $incidentId,
        ));

        if ($cmd->errorCode() != '00000') {
            $this->logger->error('Cant insert attachment for email ' . $emailId, $cmd->errorInfo());
            throw new EmailException('Cant insert attachment for email');
        }

        if (!file_exists($attachment["filePath"])) {
            throw new \RuntimeException('Attachment save error');
        }
        $storage->storeFile($fileRealName, $attachment["filePath"]);

        $this->insertAccess("iris_file", $attachment["fileId"], $mailboxId);

        return $attachment["fileId"];
    }

    protected function insertAccess($tableName, $recordId, $mailboxId)
    {
        $sql = "insert into ".$tableName."_access (ID, RecordID, ContactID, R, W, D, A) 
          select iris_genguid() as id, :recordid as recordid, contactid, r, w, d, a from iris_emailaccount_defaultaccess
          where emailaccountid = (select emailaccountid from iris_emailaccount_mailbox where id=:mailboxid)";

        $cmd = $this->connection->prepare($sql);
        $cmd->execute(array(
            ":recordid" => $recordId,
            ":mailboxid" => $mailboxId,
        ));
    }

    protected function updateLastFetchUid($mailboxId, $uid)
    {
        $this->logger->debug("updateLastFetchUid", [
            'mailbox_id' => $mailboxId,
            'uid' => $uid,
        ]);
        $cmd = $this->connection->prepare("
            update iris_emailaccount_mailbox 
            set lastuid = case when coalesce(lastuid, 0) < coalesce(:uid1, 0) then coalesce(:uid2, 0) else lastuid end  
            where id = :id
        ");
        $cmd->execute(array(
            ":id" => $mailboxId,
            ":uid1" => $uid,
            ":uid2" => $uid,
        ));
    }

    private function debug($caption, $value = null)
    {
        $this->logger->addDebug("$caption " . var_export($value, true));
    }
}
