<div class="panel">
  <div class="panel-heading">
    <i class="icon-cogs"></i>
    {l s='PC Customer ID for customers adress' mod='pccustomerid'}
  </div>

  <div class="alert alert-info">
    {l s='Settings below apply to:' mod='pccustomerid'} <strong>{$pc_shop_context_label}</strong>.
    {l s='Use the shop context selector at the top of the page to configure a specific shop or shop group differently (e.g. to keep the module disabled on the USA shop).' mod='pccustomerid'}
  </div>

  <form action="{$pc_action_url}" method="post" class="defaultForm form-horizontal">
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Enable module for this context' mod='pccustomerid'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="PCCUSTOMERID_ENABLED" id="pc_enabled_on" value="1" {if $pc_config.enabled}checked="checked"{/if}>
          <label for="pc_enabled_on">{l s='Yes' mod='pccustomerid'}</label>
          <input type="radio" name="PCCUSTOMERID_ENABLED" id="pc_enabled_off" value="0" {if !$pc_config.enabled}checked="checked"{/if}>
          <label for="pc_enabled_off">{l s='No' mod='pccustomerid'}</label>
          <a class="slide-button btn"></a>
        </span>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Excluded shop IDs (CSV)' mod='pccustomerid'}</label>
      <div class="col-lg-9">
        <input type="text" name="PCCUSTOMERID_EXCLUDED_SHOP_IDS" value="{$pc_config.excluded_shop_ids}" class="form-control fixed-width-xxl">
        <p class="help-block">{l s='Shop IDs where the module must stay off regardless of the per-shop toggle above (auto-filled with the USA shop at install).' mod='pccustomerid'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Switzerland country ID' mod='pccustomerid'}</label>
      <div class="col-lg-9">
        <input type="text" name="PCCUSTOMERID_CH_COUNTRY_ID" value="{$pc_config.ch_country_id}" class="form-control fixed-width-md">
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Spain country ID' mod='pccustomerid'}</label>
      <div class="col-lg-9">
        <input type="text" name="PCCUSTOMERID_ES_COUNTRY_ID" value="{$pc_config.es_country_id}" class="form-control fixed-width-md">
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Canary Islands state IDs (CSV)' mod='pccustomerid'}</label>
      <div class="col-lg-9">
        <input type="text" name="PCCUSTOMERID_CANARY_STATE_IDS" value="{$pc_config.canary_state_ids}" class="form-control fixed-width-xxl">
        <p class="help-block">{l s='ps_state.id_state values for Las Palmas / Santa Cruz de Tenerife. Use the "Run auto-detection" button below to refill from the database.' mod='pccustomerid'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Canary Islands postcode regex' mod='pccustomerid'}</label>
      <div class="col-lg-9">
        <input type="text" name="PCCUSTOMERID_CANARY_POSTCODE_REGEX" value="{$pc_config.canary_postcode_regex}" class="form-control fixed-width-xl">
        <p class="help-block">{l s='Fallback used when the state cannot be matched, e.g. ^(35|38)\d{3}$' mod='pccustomerid'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Use native ps_address.dni field' mod='pccustomerid'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="PCCUSTOMERID_USE_DNI_FIELD" id="pc_use_dni_on" value="1" {if $pc_config.use_dni_field}checked="checked"{/if}>
          <label for="pc_use_dni_on">{l s='Yes' mod='pccustomerid'}</label>
          <input type="radio" name="PCCUSTOMERID_USE_DNI_FIELD" id="pc_use_dni_off" value="0" {if !$pc_config.use_dni_field}checked="checked"{/if}>
          <label for="pc_use_dni_off">{l s='No' mod='pccustomerid'}</label>
          <a class="slide-button btn"></a>
        </span>
        <p class="help-block">{l s='Informational: this module always stores the value in the native ps_address.dni column.' mod='pccustomerid'}</p>
      </div>
    </div>

    <div class="panel-footer">
      <button type="submit" name="submitPccustomerid" class="btn btn-default pull-right">
        <i class="process-icon-save"></i> {l s='Save' mod='pccustomerid'}
      </button>
      <button type="submit" name="submitPccustomeridDetect" class="btn btn-default">
        <i class="process-icon-refresh"></i> {l s='Run auto-detection (country / Canary state IDs)' mod='pccustomerid'}
      </button>
    </div>
  </form>
</div>
