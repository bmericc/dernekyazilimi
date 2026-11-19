<?php

namespace Modules\MailForwarding\Support;

trait PostfixAdmin
{

    private function init_server() {
        return app(PostfixAdminClient::class)->connect() ?? false;
    }

    public function create_alias($alias_email, $target_email) {
        $xmlrpc = $this->init_server();

        if($xmlrpc) {
            $alias = $xmlrpc->getProxy('alias');
            $result = $alias->create($alias_email, $target_email);

            if($result) {
                return $result;
            }
        }

        return false;
    }

}
