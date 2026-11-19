<?php
/**
 * Postfix Admin XML-RPC interface for the Dernek Yazılımı portal.
 *
 * Replaces the stock xmlrpc.php, which needs Zend Framework 1 (not packaged
 * on Debian 13). Uses laminas/laminas-xmlrpc, installed in /opt/pfa-xmlrpc.
 *
 * Usage: log in as a PostfixAdmin admin with login(), then call alias.create
 * and alias.update. The client must send the session cookie back with every
 * request, otherwise the login does not persist.
 *
 * The portal's copy of this file is mailserver/xmlrpc_server.php in the
 * dernekyazilimi repository; keep both identical.
 */
require_once(dirname(__FILE__) . '/common.php');

if ($CONF['xmlrpc_enabled'] == false) {
    die("xmlrpc support disabled");
}

require_once('/opt/pfa-xmlrpc/vendor/autoload.php');

$server = new Laminas\XmlRpc\Server();

/**
 * @param string $username
 * @param string $password
 * @return boolean true on success, else false.
 */
function login($username, $password) {
    $login = new Login('admin');
    if (!$login->login($username, $password)) {
        return false;
    }

    init_session($username, true);
    $_SESSION['authenticated'] = true;

    $h = new AdminHandler();
    if ($h->init($username) && $h->view()) {
        $admin = $h->result();
        if (!empty($admin['superadmin'])) {
            $_SESSION['sessid']['roles'][] = 'global-admin';
        }
    }

    return true;
}

if (!isset($_SESSION['authenticated'])) {
    $server->addFunction('login', 'login');
} else {
    $server->setClass('AliasProxy', 'alias');
}
echo $server->handle();


class AliasProxy {
    /**
     * Creates an alias that forwards to one address.
     *
     * @param string $email alias address (a one-element array is accepted too)
     * @param string $forward target address
     * @return boolean true on success
     */
    public function create($email, $forward) {
        $email = is_array($email) ? (string) reset($email) : (string) $email;
        if (strpos($email, '@') === false) {
            return false;
        }
        list($localpart, $domain) = explode('@', $email, 2);

        $ah = new AliasHandler(1, $_SESSION['sessid']['username']);
        if (!$ah->init($email)) {
            error_log('xmlrpc alias.create: init failed for ' . $email . ': ' . print_r($ah->errormsg, true));
            return false;
        }

        $values = [
            'localpart' => $localpart,
            'domain' => $domain,
            'goto' => [$forward],
            'active' => 1,
        ];

        if (!$ah->set($values)) {
            error_log('xmlrpc alias.create: set failed for ' . $email . ': ' . print_r($ah->errormsg, true));
            return false;
        }

        return $ah->save();
    }

    /**
     * Points an existing alias at a new address.
     *
     * @param string $email alias address
     * @param string $forward new target address
     * @return boolean true on success
     */
    public function update($email, $forward) {
        $email = (string) $email;
        $ah = new AliasHandler(0, $_SESSION['sessid']['username']);

        if (!$ah->init($email)) {
            error_log('xmlrpc alias.update: init failed for ' . $email . ': ' . print_r($ah->errormsg, true));
            return false;
        }

        if (!$ah->set(['goto' => [$forward]])) {
            error_log('xmlrpc alias.update: set failed for ' . $email . ': ' . print_r($ah->errormsg, true));
            return false;
        }

        return $ah->save();
    }
}
/* vim: set expandtab softtabstop=4 tabstop=4 shiftwidth=4: */
