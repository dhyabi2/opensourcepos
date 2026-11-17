<?php

return [
    'description'           => 'Accept Nano (XNO) in the sales register, verified against a Nano node you choose.',
    'payment_type'          => 'Nano (XNO)',
    'show_request'          => 'Nano (XNO)',
    'amount'                => 'Amount to send',
    'account'               => 'Send to account',
    'representative'        => 'Representative to set on the send block',
    'block_hash'            => 'Block hash',
    'verify_and_add'        => 'Verify and add',
    'enter_block_hash'      => 'Enter the hash of the Nano send block.',
    'not_enabled'           => 'Nano payments are not enabled.',
    'not_initiated'         => 'Start the Nano payment first so the exact amount and account can be shown.',
    'payment_refused'       => 'Nano payment refused: {0}',
    'quote_changed'         => 'The sale changed after the Nano amount was shown. Show the Nano request again for the new amount.',
    'config_account'        => 'Receiving account (nano_...)',
    'config_node_url'       => 'Node RPC URL (read only, no wallet)',
    'config_xno_price'      => 'XNO per one unit of the sale currency',
    'config_replay_window'  => 'Accept blocks no older than (seconds)',
    'config_claims_path'    => 'Claims ledger file (empty: writable/nano/claims.log)',
    'config_saved'          => 'Nano settings saved.',
];
