<?php
/*
 * Demir Mechanical – website form settings
 *
 * ONE-TIME SETUP  (Hostinger → File Manager → public_html)
 *   1. Right-click this file → Rename → form-config.php
 *   2. Right-click form-config.php → Edit → type the password of the
 *      info@demirmechanical.com mailbox between the two quotes below → Save.
 *
 * The password stays on your server; visitors can never see this file.
 * Website updates never include form-config.php, so your setting is kept.
 * Without a password the forms still send, using the server's basic mail
 * function, but those emails are more likely to land in spam.
 */
return [
    'to'            => 'info@demirmechanical.com',     // who receives website requests
    'from'          => 'info@demirmechanical.com',   // mailbox that sends them (must exist in hPanel → Emails)
    'smtp_password' => '',                // ← password of the "from" mailbox
];
