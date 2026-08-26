<?php

namespace Eccube\Entity;

class MailTemplate
{
    private $name;
    private $file_name;
    private $mail_subject;

    public function getName() { return $this->name; }
    public function setName($name) { $this->name = $name; return $this; }
    public function getFileName() { return $this->file_name; }
    public function setFileName($f) { $this->file_name = $f; return $this; }
    public function getMailSubject() { return $this->mail_subject; }
    public function setMailSubject($s) { $this->mail_subject = $s; return $this; }
}
