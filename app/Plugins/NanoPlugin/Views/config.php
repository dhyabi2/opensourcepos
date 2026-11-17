<?php
/**
 * @var array $settings
 */
$fields = [
    'nano_account'               => 'config_account',
    'nano_node_url'              => 'config_node_url',
    'nano_xno_price'             => 'config_xno_price',
    'nano_replay_window_seconds' => 'config_replay_window',
    'nano_claims_path'           => 'config_claims_path',
];
?>

<?= form_open(site_url('plugins/saveConfig/nano'), ['id' => 'config_form', 'class' => 'form-horizontal']) ?>
<div id="config_wrapper">
    <fieldset id="config_info">
        <div id="plugins_header"><?= lang('NanoPlugin.description') ?></div>
        <ul id="error_message_box" class="error_message_box"></ul>

        <?php foreach ($fields as $name => $label): ?>
            <div class="form-group form-group-sm">
                <?= form_label(lang('NanoPlugin.' . $label), $name, ['class' => 'control-label col-xs-4']) ?>
                <div class="col-xs-8">
                    <?= form_input([
                        'name'  => $name,
                        'id'    => $name,
                        'class' => 'form-control input-sm',
                        'value' => esc($settings[$name] ?? ''),
                    ]) ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?= form_submit([
            'name'  => 'submit_nano',
            'id'    => 'submit_nano',
            'value' => lang('Common.submit'),
            'class' => 'btn btn-primary btn-sm pull-right',
        ]) ?>
    </fieldset>
</div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#config_form').validate($.extend(form_support.handler, {
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                        if (response.success) {
                            $('#plugin-config-modal').modal('hide');
                        }
                    },
                    dataType: 'json'
                });
            },
            errorLabelContainer: '#error_message_box'
        }));
    });
</script>
