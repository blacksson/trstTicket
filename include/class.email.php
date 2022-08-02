<?php
/*********************************************************************
    class.email.php

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require_once(INCLUDE_DIR.'laminas-mail/vendor/autoload.php');
include_once INCLUDE_DIR.'class.role.php';
include_once(INCLUDE_DIR.'class.dept.php');
include_once(INCLUDE_DIR.'class.mail.php');
include_once(INCLUDE_DIR.'class.mailer.php');
include_once(INCLUDE_DIR.'class.oauth2.php');
include_once(INCLUDE_DIR.'class.mailfetch.php');
include_once(INCLUDE_DIR.'class.mailparse.php');
include_once(INCLUDE_DIR.'api.tickets.php');

class Email extends VerySimpleModel {
    static $meta = array(
        'table' => EMAIL_TABLE,
        'pk' => array('email_id'),
        'joins' => array(
            'priority' => array(
                'constraint' => array('priority_id' => 'Priority.priority_id'),
                'null' => true,
            ),
            'dept' => array(
                'constraint' => array('dept_id' => 'Dept.id'),
                'null' => true,
            ),
            'topic' => array(
                'constraint' => array('topic_id' => 'Topic.topic_id'),
                'null' => true,
            ),
            'mailbox' => array(
                'reverse' => 'MailBoxAccount.account',
                'list' => false,
                'null' => true,
            ),
            'smtp' => array(
                'reverse' => 'SmtpAccount.account',
                'list' => false,
                'null' => true,
            ),
        )
    );

    const PERM_BANLIST = 'emails.banlist';

    static protected $perms = array(
            self::PERM_BANLIST => array(
                'title' =>
                /* @trans */ 'Banlist',
                'desc'  =>
                /* @trans */ 'Ability to add/remove emails from banlist via ticket interface',
                'primary' => true,
            ));

    private $stash;
    private $address;

    function getId() {
        return $this->email_id;
    }

    function __toString() {
        return $this->getAddress();
    }

    function stash($key, $data) {
        if (!isset($this->stash))
            $this->stash = &$_SESSION[':email'][$this->getId()];

        $this->stash[$key] = $data;
    }

    function stashFormData(array $data) {
        $this->stash('formdata', array_filter($data));
    }

    function getEmail() {
        return $this->email;
    }

    function getAddress() {
        if (!isset($this->address))
            $this->address = $this->name
            ? sprintf('%s <%s>', $this->name, $this->email)
            : $this->email;

        return $this->address;
    }

    function getName() {
        return $this->name;
    }

    function getPriorityId() {
        return $this->priority_id;
    }

    function getDeptId() {
        return $this->dept_id;
    }

    function getDept() {
        return $this->dept;
    }

    function getTopicId() {
        return $this->topic_id;
    }

    function getTopic() {
        return $this->topic;
    }

    function autoRespond() {
        return !$this->noautoresp;
    }

    function getHashtable() {
        return $this->ht;
    }

    static function getSupportedAuthTypes() {
        static $auths  = null;
        if (!isset($auths)) {
            $auths = [];
            // OAuth auth
            foreach (Oauth2AuthorizationBackend::allRegistered() as $id => $bk)
                $auths[$id] = $bk->getName();
            // Basic authentication
            $auths['basic'] = sprintf('%s (%s)',
                    __('Basic Authentication'),
                    __('Legacy'));
        }

        return $auths;
    }

    static function getSupportedSMTPAuthTypes() {
        return array_merge([
                'mailbox' => sprintf('%s  %s',
                    __('Same as'), __('Remote Mailbox')),
                'none' => sprintf('%s - %s',
                    __('None'), __('No Authentication Required'))],
                self::getSupportedAuthTypes());
    }

    function getInfo() {
        // Base information mimus objects
        $info = array_filter($this->getHashtable(), function($e) {
                    return !is_object($e);
                });
        // Remote Mailbox Info
        if (($mailbox=$this->getMailBoxAccount()))
            $info = array_merge($info, $mailbox->getInfo());
        // SMTP Account Info
        if (($smtp=$this->getSmtpAccount()))
            $info = array_merge($info, $smtp->getInfo());

        return $info;
    }

    function getMailBoxAccount($autoinit=true) {
        if (!$this->mailbox && isset($this->email_id) && $autoinit)
            $this->mailbox = MailBoxAccount::create([
                    'email_id' => $this->email_id]);

        return $this->mailbox;
    }

    function getSmtpAccount($autoinit=true) {
        if (!$this->smtp && isset($this->email_id) && $autoinit)
            $this->smtp = SmtpAccount::create([
                    'email_id' => $this->email_id]);

        return $this->smtp;
    }

    function getAuthAccount($which) {
        $account = null;
        switch ($which) {
            case 'mailbox':
                $account  = $this->getMailBoxAccount();
                break;
            case 'smtp':
                $account = $this->getSmtpAccount();
                break;
        }
        return $account;
    }

    function send($to, $subject, $message, $attachments=null, $options=null, $cc=array()) {
        $mailer = new osTicket\Mail\Mailer($this);
        if($attachments)
            $mailer->addAttachments($attachments);

        return $mailer->send($to, $subject, $message, $options, $cc);
    }

    function sendAutoReply($to, $subject, $message, $attachments=null, $options=array()) {
        $options+= array('autoreply' => true);
        return $this->send($to, $subject, $message, $attachments, $options);
    }

    function sendAlert($to, $subject, $message, $attachments=null, $options=array()) {
        $options+= array('notice' => true);
        return $this->send($to, $subject, $message, $attachments, $options);
    }

   function delete() {
        global $cfg;
        //Make sure we are not trying to delete default emails.
        if(!$cfg || $this->getId()==$cfg->getDefaultEmailId() || $this->getId()==$cfg->getAlertEmailId()) //double...double check.
            return 0;

        if (!parent::delete())
            return false;

        $type = array('type' => 'deleted');
        Signal::send('object.deleted', $this, $type);

        // Delete email accounts
        if ($this->mailbox)
            $this->mailbox->delete();
        if ($this->smtp)
            $this->smtp->delete();

        Dept::objects()
            ->filter(array('email_id' => $this->getId()))
            ->update(array(
                'email_id' => $cfg->getDefaultEmailId()
            ));

        Dept::objects()
            ->filter(array('autoresp_email_id' => $this->getId()))
            ->update(array(
                'autoresp_email_id' => 0,
            ));

        return true;
    }

    function save($refetch=false) {
        if ($this->dirty)
            $this->updated = SqlFunction::NOW();

        return parent::save($refetch || $this->dirty);
    }

    function update($vars, &$errors=false) {
        global $cfg;

        // very basic checks
        $vars['name'] = Format::striptags(trim($vars['name']));
        $vars['email'] = trim($vars['email']);
        $id = isset($this->email_id) ? $this->getId() : 0;
        if ($id && $id != $vars['id'])
            $errors['err']=__('Get technical help!')
                .' '.__('Internal error occurred');

        if (!$vars['email'] || !Validator::is_email($vars['email'])) {
            $errors['email']=__('Valid email required');
        } elseif (($eid=Email::getIdByEmail($vars['email'])) && $eid != $id) {
            $errors['email']=__('Email already exists');
        } elseif ($cfg && !strcasecmp($cfg->getAdminEmail(), $vars['email'])) {
            $errors['email']=__('Email already used as admin email!');
        } elseif (Staff::getIdByEmail($vars['email'])) { //make sure the email doesn't belong to any of the staff
            $errors['email']=__('Email in use by an agent');
        }

        if (!$vars['name'])
            $errors['name']=__('Email name required');

        /*
         TODO: ???
        $dept = Dept::lookup($vars['dept_id']);
        if($dept && !$dept->isActive())
          $errors['dept_id'] = '';

        $topic = Topic::lookup($vars['topic_id']);
        if($topic && !$topic->isActive())
          $errors['topic_id'] = '';
        */

        // Remote Mailbox Settings
        if (($mailbox = $this->getMailBoxAccount()))
            $mailbox->update($vars, $errors);
        // SMTP Settings
        if (($smtp = $this->getSmtpAccount()))
            $smtp->update($vars, $errors);

        //abort on errors
        if ($errors)
            return false;

        if ($errors) return false;

        // Update basic settings
        $this->email = $vars['email'];
        $this->name = Format::striptags($vars['name']);
        $this->dept_id = $vars['dept_id'];
        $this->priority_id = isset($vars['priority_id']) ? $vars['priority_id'] : '0';
        $this->topic_id = $vars['topic_id'];
        $this->noautoresp = $vars['noautoresp'];
        $this->notes = Format::sanitize($vars['notes']);

        if ($this->save())
            return true;

        if ($id) { //update
            $errors['err'] = sprintf(__('Unable to update %s.'), __('this email'))
               .' '.__('Internal error occurred');
        } else {
            $errors['err'] = sprintf(__('Unable to add %s.'), __('this email'))
               .' '.__('Internal error occurred');
        }

        return false;
    }

   static function getIdByEmail($email) {
        $qs = static::objects()->filter(Q::any(array(
                        'email'  => $email,
                        )))
            ->values_flat('email_id');

        $row = $qs->first();
        return $row ? $row[0] : false;
    }

    static function create($vars=false) {
        $inst = new static($vars);
        $inst->created = SqlFunction::NOW();
        return $inst;
    }

    static function getAddresses($options=array(), $flat=true) {
        $objects = static::objects();
        if ($options['smtp'])
            $objects = $objects->filter(array('smtp__active' => 1));

        if ($options['depts'])
            $objects = $objects->filter(array('dept_id__in'=>$options['depts']));

        if (!$flat)
            return $objects;

        $addresses = array();
        foreach ($objects->values_flat('email_id', 'email') as $row) {
            list($id, $email) = $row;
            $addresses[$id] = $email;
        }
        return $addresses;
    }

    static function getPermissions() {
        return self::$perms;
    }

    // Supported Remote Mailbox protocols
    static function mailboxProtocols() {
        return [
            'IMAP' => 'IMAP',
            'POP'  => 'POP'];
    }
}
RolePermission::register(/* @trans */ 'Miscellaneous', Email::getPermissions());

class EmailAccount extends VerySimpleModel {
    static $meta = array(
        'table' => EMAIL_ACCOUNT_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'email' => array(
                'constraint' => array('email_id' => 'Email.email_id'),
             ),
        ),
    );

    private $bkId;
    private $form;
    private $cred;
    private $config;
    private $instance;
    // If account supports tls or ssl
    private $encryption = false;

    public function getAccountOptions() {
        return new osTicket\Mail\AccountOptions($this);
    }

    public function getHost() {
        return $this->host;
    }

    public function getPort() {
        return $this->port;
    }

    public function getEncryption() {
        return $this->encryption;
    }

    public function getNumErrors() {
        return $this->num_errors;
    }

    public function isOAuthAuth() {
        return str_starts_with($this->getAuthBk(), 'oauth');
    }

    public function isBasicAuth() {
        return str_starts_with($this->getAuthBk(), 'basic');
    }

    public function isActive() {
        return $this->active;
    }

    public function isEnabled() {
        return $this->isOAuthAuth()
            ? (($i=$this->getOAuth2Instance()) && $i->isEnabled())
            : true;
    }

    public function shouldAuthorize() {
        // check status and make sure it's oauth
        if (!$this->isEnabled() || !$this->isOAuthAuth())
            return false;

        // No credentials stored or token expired (referesh failed)
        if (!($cred=$this->getFreshCredentials())
                || !($token=$cred->getAccessToken())
                || $token->isExpired())
            return true;

        // Signature mismatch - means config changed
        return strcasecmp($token->getConfigSignature(),
                $this->getConfigSignature());
    }

    public function getId() {
        return $this->id;
    }

    public function getType() {
        return $this->type;
    }

    public function getAuthBk() {
        return $this->auth_bk;
    }

    public function getAuthId() {
        return $this->auth_id;
    }

    public function getBkId() {
        if  (!isset($this->bkId)) {
            $id = sprintf('%s:%d',
                $this->getAuthBk(), $this->getId());
            if ($this->isOAuthAuth())
                $id .= sprintf(':%d', $this->getAuthId());

            $this->bkId = $id;
        }
        return $this->bkId;
    }

    public function getEmailId() {
        return $this->email_id;
    }

    public function getEmail() {
        return $this->email;
    }

    private function getOAuth2Backend($auth=null) {
        $auth = $auth ?: $this->getAuthBk();
        return Oauth2AuthorizationBackend::getBackend($auth);
    }

    public function getOAuth2ConfigDefaults() {
        $email  = $this->getEmail();
        return  [
            'auth_type' => 'autho',
            'auth_name' => $email->getName(),
            'name' => sprintf('%s (%s)',
                    $email->getEmail(), $this->getType()),
            'isactive' => 1,
            'notes' => sprintf(
                    __('OAuth2 Authorization for %s'), $email->getEmail()),
        ];
    }

    public function getOAuth2ConfigInfo()  {
        $vars = $this->getOAuth2ConfigDefaults();
        if (($i=$this->getOAuth2Instance()))
            $vars = array_merge($vars, $i->getInfo());
        return $vars;
    }

    private function getOAuth2ConfigForm($vars, $auth=null) {
        // Lookup OAuth2 backend & Get basic config form
         if (($bk=$this->getOAuth2Backend($auth)))
             return $bk->getConfigForm(
                     array_merge(
                         $this->getOAuth2ConfigDefaults(),
                         $vars ?: $bk->getDefaults() #nolint
                         ),
                     !strcmp($auth, $this->getAuthBk())
                     ? $this->getAuthId() : null
                     );
    }

    private function getBasicAuthConfigForm($vars, $auth=null) {
        $creds = $this->getCredentialsVars($auth) ?: [];
        if (!$vars &&  $creds) {
            $vars = [
                'username' => $creds['username'],
                'passwd' => $creds['password'],
            ];
        } elseif (!$_POST && !isset($vars['username']) && $this->email)
            $vars['username'] = $this->email->getEmail();

        if (!isset($vars['passwd']) && $_POST && $creds)
            $vars['passwd'] = $creds['password'];

        return new BasicAuthConfigForm($vars);
    }

    public function getOAuth2Instance($bk=null) {
        $bk = $bk ?: $this->getOAuth2Backend();
        if (!isset($this->instance) && $this->getAuthId() && $bk)
            $this->instance = $bk->getPluginInstance($this->getAuthId());

        return $this->instance;
    }

    public function getConfigSignature() {
        if (($i=$this->getOAuth2Instance()))
            return $i->getSignature();
    }

    public function getInfo() {
        $ht = array();
        foreach (static::$vars as $var) {
            if (isset($this->ht[$var]))
                $ht[$this->type.'_'.$var] = $this->ht[$var];
        }
        return $ht;
    }

    private function getNamespace() {
        return sprintf('email.%d.account.%d',
                 $this->getEmailId(),
                 $this->getId());
    }

    private function getConfig() {
        if (!isset($this->config))
            $this->config = new EmailAccountConfig($this->getNamespace());
        return $this->config;
    }

    public function getAuthConfigForm($auth, $vars=false) {
        if (!isset($this->form) || strcmp($auth, $this->getAuthBk())) {
            list($type, $provider) = explode(':', $auth);
            switch ($type) {
                case 'oauth2':
                    $this->form = $this->getOAuth2ConfigForm($vars, $auth);
                    break;
                case 'basic':
                     $this->form = $this->getBasicAuthConfigForm($vars,
                             $auth);
                    break;
            }
        }
        return $this->form;
    }

    public function saveAuth($auth, $form, &$errors) {
        // Validate the form
        if (!$form->isValid())
            return false;
        $vars = $form->getClean();
        list($type, $provider) = explode(':', $auth);
        switch ($type) {
            case 'basic':
                // Set username and password
                if (!$vars || !$this->updateCredentials($auth, $vars, $errors))
                    $errors['err'] = sprintf('%s %s',
                            __('Error Saving'),
                            __('Authentication'));
                break;
            case 'oauth2':
                // For OAuth we are simply saving configuration -
                // credetials are saved post successful authorization
                // redirect.

                // Lookup OAuth backend
                if (($bk=$this->getOAuth2Backend($auth))) {
                    // Merge form data, post vars and any defaults
                    $vars = array_merge($this->getOAuth2ConfigDefaults(),
                            array_intersect_key($_POST, $this->getOAuth2ConfigDefaults()),
                            $vars);
                    // Update or add OAuth2 instance
                    if ($this->getAuthId()
                            && ($i=$bk->getPluginInstance($this->getAuthId()))) {
                        $vars = array_merge($bk->getDefaults(), $vars); #nolint
                        if (!$i->update($vars, $errors))
                            $errors['err'] = sprintf('%s %s',
                                    __('Error Saving'),
                                     __('Authentication'));
                    } else {
                        // Ask the backend to add OAuth2 instance for this account
                        if (($i=$bk->addPluginInstance($vars, $errors))) { #nolint
                            // Cache instance
                            $this->instance = $i;
                            $this->auth_bk = $auth;
                            $this->auth_id = $i->getId();
                            $this->save();
                        } else {
                            $errors['err'] = __('Error Adding OAuth2 Instance');
                        }
                    }
                }
                break;
             default:
                 $errors['err'] = __('Unknown Authentication Type');
         }
         return !($errors);
    }

    public function logError($error) {
        return $this->logActivity($error);
    }

    private function getCredentialsVars($auth=null) {
        $vars = [];
        if (($cred = $this->getCredentials($auth)))
            $vars = $cred->toArray();

        return $vars;
    }

    public function getFreshCredentials($auth=null) {
        return $this->getCredentials($auth, true);
    }

    public function getCredentials($auth=null, $refresh=false) {
        // Authentication doesn't match - it's getting reconfigured.
        if ($auth
                && strncasecmp($this->getAuthBk(), $auth, strlen($auth))
                && strcasecmp($auth, 'none'))
            return [];

        if (!isset($this->cred) || $refresh)  {
            $cred = null;
            $auth = $auth ?: $this->getAuthBk();
            list($type, $provider) = explode(':', $auth);
            switch ($type) {
                case 'mailbox':
                    if (($mb=$this->email->getMailBoxAccount())
                            && $mb->getAuthBk())
                        $cred = $mb->getCredentials($mb->getAuthBk(), $refresh);
                    break;
                case 'none':
                    // No authentication required (open replay)
                    $cred = new osTicket\Mail\NoAuthCredentials([
                            'username' => $this->email->getEmail()]);
                    break;
                case 'basic':
                    if (($c=$this->getConfig())
                            && ($creds=$c->toArray())
                            && isset($creds['username'])
                            && isset($creds['passwd'])) {
                        // Decrypt password
                        $cred = new osTicket\Mail\BasicAuthCredentials([
                                'username' => $creds['username'],
                                // Decrypt password
                                'password' => Crypto::decrypt($creds['passwd'],
                                    SECRET_SALT,
                                    md5($creds['username'].$this->getNamespace()))
                        ]);
                    }
                    break;
                case 'oauth2':
                    if (($c=$this->getConfig()) && ($creds=$c->toArray())) {
                        // Decrypt Access Token
                        if ($creds['access_token']) {
                            $creds['access_token'] = Crypto::decrypt(
                                    $creds['access_token'],
                                    SECRET_SALT,
                                    md5($creds['resource_owner_email'].$this->getNamespace())
                                    );
                        }
                         // Decrypt Referesh Token
                        if ($creds['refresh_token']) {
                            $creds['refresh_token'] = Crypto::decrypt(
                                    $creds['refresh_token'],
                                    SECRET_SALT,
                                    md5($creds['resource_owner_email'].$this->getNamespace())
                                    );
                        }
                        $errors = [];
                        $class = 'osTicket\Mail\OAuth2AuthCredentials';
                        try {
                            // Init credentials and see of we need to
                            // refresh the token
                            if (($cred=$class::init($creds))
                                    && ($token=$cred->getToken())
                                    && ($refresh && $token->isExpired())
                                    && ($bk=$this->getOAuth2Backend())
                                    && ($info=$bk->refreshAccessToken( #nolint
                                            $token->getRefreshToken(),
                                            $this->getBkId(), $errors))
                                    && isset($info['access_token'])
                                    && $this->updateCredentials($auth,
                                        // Merge new access token with
                                        // already decrypted creds
                                        array_merge($creds, $info), $errors
                                        )) {
                                return $this->getCredentials($auth, $refresh);
                            } elseif ($errors) {
                                $errors['err']  = $errors['refresh_token']
                                    ?: __('Referesh Token Expired');
                            }
                        } catch (Exception $ex) {
                            $errors['err']  = $ex->getMessage();
                        }
                        if (isset($errors['err']))
                            $this->logError($errors['err']);
                    }
                    break;
                default:
                    throw new Exception(sprintf('%s: %s',
                                $type, __('Unknown Credential Type')));
            }
            $this->cred = $cred;
        }
        return $this->cred;
    }

    public function updateCredentials($auth, $vars, &$errors) {
        if (!$vars || $errors)
            return false;

        list($type, $provider) = explode(':', $auth);
        switch ($type) {
            case 'basic':
                // Get current credentials - we need to re-encrypt
                // password as username might be changing
                $creds = $this->getCredentialsVars($auth);
                // password change?
                if (!$vars['username']) {
                    $errors['username'] = __('Username Required');
                } elseif (!$vars['passwd'] && !$creds['password']) {
                     $errors['passwd'] = __('Password Required');
                } elseif (!$errors) {
                    $info = [
                        // username
                        'username' => $vars['username'],
                        // Encrypt  password
                        'passwd'   => Crypto::encrypt($vars['passwd'] ?:
                                $creds['password'],  SECRET_SALT,
                                 md5($vars['username'].$this->getNamespace()))
                    ];
                    if (!$this->getConfig()->updateInfo($info))
                        $errors['err'] = sprintf('%s: %s',
                                Format::htmlchars($type),
                                __('Error saving credentials'));
                }
                break;
            case 'oauth2':
                if (!$vars['access_token']) {
                    $errors['access_token'] = __('Access Token Required');
                } elseif (!$vars['resource_owner_email']
                        || !Validator::is_email($vars['resource_owner_email'])) {
                    $errors['resource_owner_email'] =
                        __('Resource Owner Required');

                } else {
                    // Encrypt Access Token
                    $vars['access_token'] = Crypto::encrypt(
                            $vars['access_token'],
                             SECRET_SALT,
                             md5($vars['resource_owner_email'].$this->getNamespace()));
                     // Encrypt Referesh Token
                    if ($vars['refresh_token']) {
                        $vars['refresh_token'] = Crypto::encrypt(
                                $vars['refresh_token'],
                                SECRET_SALT,
                                md5($vars['resource_owner_email'].$this->getNamespace())
                                );
                    }
                    $vars['config_signature'] = $this->getConfigSignature();
                    if (!$this->getConfig()->updateInfo($vars))
                        $errors['err'] = sprintf('%s: %s',
                                 Format::htmlchars($type),
                                 __('Error saving credentials'));
                }
                break;
            default:
                 $errors['err'] =  sprintf('%s - %s',
                         __('Unknown Authentication'),
                         Format::htmlchars($auth));
        }

        if ($errors)
            return false;

        $this->auth_bk = $auth;
        // Clear cached credentials
        $this->creds = null;
        return $this->save();
    }

    function update($vars, &$errors) {
        return false;
    }

    public function logActivity($error= null, $now=null) {
        if (isset($error)) {
            $this->num_errors += 1;
            $this->last_error_msg = $error;
            $this->last_error = $now ?: SqlFunction::NOW();
        } else {
            $this->num_errors = 0;
            $this->last_error = null;
            $this->last_activity =  $now ?: SqlFunction::NOW();
        }
        $this->save();
    }

    function save($refetch=false) {
        if ($this->dirty) {
            $this->updated = SqlFunction::NOW();
        }
        return parent::save($refetch || $this->dirty);
    }

    function delete() {
        // Destroy the Email config
        $this->getConfig()->destroy();
        // Delete the Plugin instance
        if ($this->isOAuthAuth() && ($i=$this->getOAuth2Instance()))
            $i->delete();
        // Delete the EmailAccount
        parent::delete();
    }

    static function create($ht=false) {
        $i = new static($ht);
        $i->active = 0;
        $i->created = SqlFunction::NOW();
        return $i;
    }
}

class MailBoxAccount extends EmailAccount {
    static $meta = array(
        'table' => EMAIL_ACCOUNT_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'email' => array(
                'constraint' => array('email_id' => 'Email.email_id'),
             ),
            'account' => array(
                'constraint' => array(
                    'type' => "'mailbox'",
                    'email_id' => 'Email.email_id'),
            ),
        ),
    );
    static protected $vars = [
        'active', 'host', 'port', 'protocol', 'auth_bk', 'folder',
        'fetchfreq', 'fetchmax', 'postfetch', 'archivefolder'
    ];

    private $cred;
    private $mailbox;

    static public function objects() {
        return parent::objects()
            ->filter(['type' => 'mailbox']);
    }

    public function getProtocol() {
        return $this->protocol;
    }

    public function getFolder() {
        return $this->folder;
    }

    public function getArchiveFolder() {
        if ((strcasecmp($this->getProtocol(), 'imap') == 0) && $this->archivefolder)
            return $this->archivefolder;
    }

    public function canDeleteEmails() {
        return !strcasecmp($this->postfetch, 'delete');
    }

    public function getMaxFetch() {
        return $this->fetchmax;
    }

    public function getMailBox(osTicket\Mail\AuthCredentials $cred=null) {
        if (!isset($this->mailbox) || $cred) {
            $this->cred = $cred ?: $this->getFreshCredentials();
            $options = $this->getAccountOptions();
            switch  (strtolower($this->getProtocol())) {
                case 'imap':
                    $mailbox = new osTicket\Mail\Imap($options);
                    break;
                case 'pop3':
                case 'pop':
                    $mailbox = new osTicket\Mail\Pop3($options);
                    break;
                default:
                    throw new Exception('Unknown Mail protocol:
                            '.$this->getProtocol());
            }
            $this->mailbox = $mailbox;
        }
        return $this->mailbox;
    }

    public function fetchEmails() {
        try {
            $fetcher = new osTicket\Mail\Fetcher($this);
            $num = $fetcher->processEmails();
            $this->logLastFetch();
            return $num;
        } catch (Exception $ex) {
            $this->logFetchError($ex->getMessage());
           // rethrow the exception so caller can handle it
            throw $ex;
        }
        return 0;
    }

    protected function setInfo($vars, &$errors) {
        $creds = null;
        if ($vars['mailbox_active']) {
            if (!$vars['mailbox_host'])
                $errors['mailbox_host'] = __('Host name required');
            if (!$vars['mailbox_port'])
                $errors['mailbox_port'] = __('Port required');
            if (!$vars['mailbox_protocol'])
                $errors['mailbox_protocol'] = __('Select protocol');
            elseif (!in_array($vars['mailbox_protocol'], Email::mailboxProtocols()))
                $errors['mailbox_protocol'] = __('Invalid protocol');
            if (!$vars['mailbox_auth_bk'])
                $errors['mailbox_auth_bk'] = __('Select Authentication');
            if (!$vars['mailbox_fetchfreq'] || !is_numeric($vars['mailbox_fetchfreq']))
                $errors['mailbox_fetchfreq'] = __('Fetch interval required');
            if (!$vars['mailbox_fetchmax'] || !is_numeric($vars['mailbox_fetchmax']))
                $errors['mailbox_fetchmax'] = __('Maximum emails required');
            if ($vars['mailbox_protocol'] == 'POP' && !empty($vars['mailbox_folder']))
                $errors['mailbox_folder'] = __('POP mail servers do not support folders');
            if (!$vars['mailbox_postfetch'])
                $errors['mailbox_postfetch'] = __('Indicate what to do with fetched emails');
        }

        if (!strcasecmp($vars['mailbox_postfetch'], 'archive')) {
            if ($vars['mailbox_protocol'] == 'POP')
                $errors['mailbox_postfetch'] =  __('POP mail servers do not support folders');
            elseif (!$vars['mailbox_archivefolder'])
                $errors['mailbox_postfetch'] = __('Valid folder required');
            elseif (!strcasecmp($vars['mailbox_folder'],
                        $vars['mailbox_archivefolder']))
                $errors['mailbox_postfetch'] = __('Archive folder cannot be same as fetched folder (INBOX)');
        }

        // Make sure authentication is configured if selection is made
        if ($vars['mailbox_auth_bk']
                && !($creds=$this->getFreshCredentials($vars['mailbox_auth_bk'])))
            $errors['mailbox_auth_bk'] = __('Configure Authentication');

        if (!$errors) {
            $this->active = $vars['mailbox_active'] ? 1 : 0;
            $this->host = $vars['mailbox_host'];
            $this->port = $vars['mailbox_port'] ?: 0;
            $this->protocol = $vars['mailbox_protocol'];
            $this->auth_bk = $vars['mailbox_auth_bk'] ?: null;
            $this->folder = $vars['mailbox_folder'] ?: null;
            $this->fetchfreq = $vars['mailbox_fetchfreq'] ?: 5;
            $this->fetchmax = $vars['mailbox_fetchmax'] ?: 30;
            $this->postfetch =  $vars['mailbox_postfetch'];
            $this->last_activity = null;
            $this->num_errors = 0;
            //Post fetch email handling...
            switch ($vars['mailbox_postfetch']) {
                case 'archive':
                    $this->archivefolder = $vars['mailbox_archivefolder'];
                    break;
                case 'delete':
                default:
                    $this->archivefolder = null;
            }
            // If mailbox is active and we have credentials then attemp to
            // authenticate
            if ($this->active && $creds) {
                try {
                    // Get mailbox (Storage Backend)
                    if (($mb=$this->getMailBox($creds))) {
                        if  ($this->folder &&
                                !$mb->hasFolder($this->folder))
                            $errors['mailbox_folder'] = __('Unknown Folder');
                        if ($this->archivefolder
                                && $this->protocol == 'IMAP'
                                && !$mb->hasFolder($this->archivefolder)
                                && !$mb->createFolder($this->archivefolder))
                            $errors['mailbox_archivefolder'] =
                                    __('Unable to create Folder');
                    } else {
                        $errors['mailbox_auth'] = __('Authentication Error');
                    }
                } catch (Exception $ex) {
                     $errors['mailbox_auth'] = $ex->getMessage();
                }
            }
        }
        return !$errors;
    }

    public function logLastFetch($now=null) {
        return $this->logActivity($now);
    }

    public function update($vars, &$errors) {
        if (!$this->setInfo($vars, $errors))
            return false;

        return $this->save();
    }

    static function create($ht=false) {
        $i = parent::create($ht);
        $i->type = 'mailbox';
        return $i;
    }
}

class SmtpAccount extends EmailAccount {
    static $meta = array(
        'table' => EMAIL_ACCOUNT_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'email' => array(
                'constraint' => array('email_id' => 'Email.email_id'),
             ),
            'account' => array(
                'constraint' => array(
                    'type' => "'smtp'",
                    'email_id' => 'Email.email_id'),
            ),
        ),
    );

    static protected $vars = [
        'active', 'host', 'port', 'protocol', 'auth_bk', 'allow_spoofing'
    ];
    private $smtp;
    private $cred;

    static public function objects() {
        return parent::objects()
            ->filter(['type' => 'smtp']);
    }

    public function allowSpoofing() {
        return ($this->allow_spoofing);
    }

    public function getSmtpConnection() {
        $this->smtp = $this->getSmtp();
        if (!$this->smtp->connect())
            return false;

        return $this->smtp;
    }

    public function getSmtp(osTicket\Mail\AuthCredentials $cred=null) {
        if (!isset($this->smtp) || $cred) {
            $this->cred = $cred ?: $this->getFreshCredentials();
            $accountOptions = $this->getAccountOptions();
            $smtpOptions = new osTicket\Mail\SmtpOptions($accountOptions);
            $smtp = new osTicket\Mail\Smtp($smtpOptions);
            // Attempt to connect if Credentials are sent
            if ($cred) $smtp->connect();
            $this->smtp = $smtp;
        }
        return $this->smtp;
    }

    protected function setInfo($vars, &$errors) {
        $creds = null;
        $_errors = [];
        if ($vars['smtp_active']) {
            if (!$vars['smtp_host'])
                $_errors['smtp_host'] = __('Host name required');
            if (!$vars['smtp_port'])
                $_errors['smtp_port'] = __('Port required');
            if (!$vars['smtp_auth_bk'])
                $_errors['smtp_auth_bk'] = __('Select Authentication');
            elseif (!($creds=$this->getFreshCredentials($vars['smtp_auth_bk'])))
                $_errors['smtp_auth_bk'] = ($vars['smtp_auth_bk'] == 'mailbox')
                    ? __('Configure Mailbox Authentication')
                    : __('Configure Authentication');
        } elseif ($vars['smtp_auth_bk']
                // We default to mailbox - so we're not going to check
                // unless account is active, see above!
                && strcasecmp($vars['smtp_auth_bk'], 'mailbox')
                && !($creds=$this->getFreshCredentials($vars['smtp_auth_bk'])))
            $_errors['smtp_auth_bk'] = __('Configure Authentication');

        if (!$_errors) {
            $this->active = $vars['smtp_active'] ? 1 : 0;
            $this->host = $vars['smtp_host'];
            $this->port = $vars['smtp_port'] ?: 0;
            $this->auth_bk = $vars['smtp_auth_bk'] ?: null;
            $this->protocol = 'SMTP';
            $this->allow_spoofing = $vars['smtp_allow_spoofing'] ? 1 : 0;
            $this->last_activity = null;
            $this->num_errors = 0;
            // If account is active then attempt to authenticate
            if ($this->active && $creds) {
                try {
                    if (!($smtp=$this->getSmtp($creds)))
                        $_errors['smtp_auth'] = __('Authentication Error');
                } catch (Exception $ex) {
                     $_errors['smtp_auth'] = $ex->getMessage();
                }
            }
        }
        $errors = array_merge($errors, $_errors);
        return !$errors;
    }

    function update($vars, &$errors) {
        if (!$this->setInfo($vars, $errors))
            return false;
        return $this->save();
    }

    static function create($ht=false) {
        $i = parent::create($ht);
        $i->type = 'smtp';
        return $i;
    }
}


/*
 * Email Config Store
 *
 * Extends base central config store
 *
 */
class EmailAccountConfig extends Config {
    public function updateInfo($vars) {
        return parent::updateAll($vars);
    }
}

/*
 * Basic Authentication Configuration Form
 *
 */
class BasicAuthConfigForm extends AbstractForm {
    function buildFields() {
        $passwdhint = '';
        if (isset($this->_source['passwd']) && $this->_source['passwd'])
           $passwdhint = __('Enter a new password to change current one');

        return array(
            'username' => new TextboxField(array(
                'required' => true,
                'label' => __('Username'),
                'configuration' => array(
                    'autofocus' => true,
                ),
            )),
            'passwd' => new PasswordField(array(
                'label' => __('Password'),
                'required' => true,
                'validator' => 'noop',
                'hint' => $passwdhint,
                'configuration' => array(
                    'classes' => 'span12',
                    'placeholder' =>  $passwdhint ?
                    str_repeat('••••••••••••', 2) : __('Password'),
                ),
            )),
        );
    }

    function isValid($include=false) {
        $self = $this;
        return parent::isValid(function ($f) use($self) {
                return !(($f instanceof PasswordField)
                        && isset($self->_source['passwd'])
                        && $f->resetErrors());
                });
    }
}
?>
