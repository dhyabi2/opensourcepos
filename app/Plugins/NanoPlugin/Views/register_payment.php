<?php
/**
 * Nano (XNO) panel in the register payment area. The cashier asks for the
 * exact request, shows it to the payer, then enters the send block hash; the
 * plugin verifies it against the node and the register reloads with the
 * verified payment.
 *
 * @var string $paymentType
 */
?>
<div id="nano_plugin_panel" style="clear: both; padding-top: 8px;">
    <button type="button" class="btn btn-sm btn-default" id="nano_show_request">
        <?= esc(lang('NanoPlugin.show_request')) ?>
    </button>
    <div id="nano_request" style="display: none; padding-top: 8px; word-break: break-all;">
        <div id="nano_error" class="text-danger"></div>
        <dl id="nano_details" style="margin-bottom: 4px; display: none;">
            <dt><?= esc(lang('NanoPlugin.amount')) ?></dt>
            <dd><span id="nano_amount_xno"></span> XNO (<span id="nano_amount_raw"></span> raw)</dd>
            <dt><?= esc(lang('NanoPlugin.account')) ?></dt>
            <dd id="nano_account"></dd>
            <dt><?= esc(lang('NanoPlugin.representative')) ?></dt>
            <dd id="nano_representative"></dd>
        </dl>
        <div class="input-group input-group-sm">
            <input type="text" class="form-control" id="nano_block_hash" maxlength="64" autocomplete="off"
                   placeholder="<?= esc(lang('NanoPlugin.block_hash'), 'attr') ?>">
            <span class="input-group-btn">
                <button type="button" class="btn btn-success" id="nano_verify"><?= esc(lang('NanoPlugin.verify_and_add')) ?></button>
            </span>
        </div>
    </div>
</div>
<script type="text/javascript">
    $(document).ready(function() {
        // The CSRF token is added to every POST by the core ajax prefilter (header_js.php).
        $('#nano_show_request').click(function() {
            $('#nano_error').text('');
            $('#nano_details').hide();
            $('#nano_request').show();
            $.get('<?= site_url('plugins/nano/paymentRequest') ?>')
                .done(function(response) {
                    $('#nano_amount_xno').text(response.amount_xno);
                    $('#nano_amount_raw').text(response.amount_raw);
                    $('#nano_account').text(response.account);
                    $('#nano_representative').text(response.representative);
                    $('#nano_details').show();
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : <?= json_encode(lang('NanoPlugin.not_initiated')) ?>;
                    $('#nano_error').text(message);
                });
        });

        $('#nano_verify').click(function() {
            const data = { block_hash: $('#nano_block_hash').val() };
            $.post('<?= site_url('plugins/nano/addPayment') ?>', data, null, 'json')
                .done(function(response) {
                    if (response.success) {
                        window.location.href = '<?= site_url('sales') ?>';
                    } else {
                        $('#nano_error').text(response.message);
                    }
                })
                .fail(function() {
                    $('#nano_error').text(<?= json_encode(lang('NanoPlugin.not_initiated')) ?>);
                });
        });
    });
</script>
