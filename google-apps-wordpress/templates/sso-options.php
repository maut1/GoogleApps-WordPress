<?php if(!$prosites_installed): ?>
<div class="error">
  <p>The ProSites plugin does not seem to be activated. Please make sure that the plugin is installed and activated for the correct settings to be applied on user registration</p>
</div>
<?php endif; ?>

<h3>General Options</h3>

<table class="form-table">

	<tr>
		<th scope="row">Consumer key</th>
		<td>
			<input type="text" class="regular-text" name="inverted_gapps_consumer_key" value="<?php echo $sso_settings['consumer_key']; ?>" />
		</td>
	</tr>

	<tr>
		<th scope="row">Consumer secret</th>
		<td>
			<input type="text" class="regular-text" name="inverted_gapps_consumer_secret" value="<?php echo $sso_settings['consumer_secret']; ?>" />
		</td>
	</tr>

	<tr>
		<th scope="row">Default role for first sign in</th>
		<td>
			<select name="inverted_gapps_default_signin_role">
				<?php foreach($roles as $role => $label): ?>
				<option value="<?php echo $role; ?>" <?php selected($role, $sso_settings['default_signin_role']); ?>><?php echo $label; ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
</table>

<h3>Pro Sites</h3>

<table class="form-table">

  <tr>
    <th scope="row">Default level for new sites</th>
    <td>
      <select name="inverted_gapps_default_prosites_level">
        <?php foreach($prosites_levels as $id => $level): ?>
        <option <?php selected($id, $sso_settings['default_prosites_level']); ?> value="<?php echo $id; ?>"><?php echo $level['name']; ?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>

  <tr>
    <th scope="row">Default period for new sites</th>
    <td>
      <select name="inverted_gapps_default_prosites_period">
        <?php foreach($prosites_periods as $period): ?>
        <option <?php selected($period, $sso_settings['default_prosites_period']); ?> value="<?php echo $period; ?>">
          <?php echo $period . " " . ($period > 1 ? "Months" : "Month"); ?>
        </option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>

</table>

