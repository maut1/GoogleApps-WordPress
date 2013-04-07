<!DOCTYPE html>
<html>

<head>

<style tyep="text/css">

body
{
	background-color: #fff;
	background-size: cover;
	font-family: 'Maven Pro', 'Trebuchet MS', sans-serif;
	font-size: 15px;
	line-height: 1.625;
	margin: 0;
}

#container
{
	width: 960px;
	margin: 0 auto;
	background: #ffffff;
	padding: 30px 40px;
	font-size: 1.2em;
}

#create-blog
{
	padding: 20px 0;
}

#logo
{
	background: url('<?php echo plugins_url(null, __FILE__); ?>/logo-extremis.png') no-repeat top left;
	width: 110px;
	height: 36px;
	float: left;
	margin: 28px 95px 0 0;
}

input[type=text]
{
	margin: 20px 0;
	border: none;
	padding: 6px 0 6px 3px;
	background: #dddddd;
	font-size: 1.2em;
}

.subdomain
{
	text-align: right;
}

.subdirectory
{
  text-align: left;
}

label
{
	width: 200px;
	display: inline-block;
}

.error
{
	display: block;
	background: #ea6e9d;
	padding: 3px 6px;
}

input[type=submit]
{
	border: none;
	font-size: 1.2em;
	padding: 8px 6px;
	cursor: pointer;
}

</style>

</head>

<body>

	<div id="container">

		<div id="logo"></div>
		<h1>Activate your new site</h1>

		<form action="" method="get" id="create-blog">
			<p>
				<?php if(isset($sso->error_info['address'])): ?>
				<span class="error"><?php echo $sso->error_info['address']; ?></span>
				<?php endif; ?>

				<label>Your Site Address</label>
        <?php if(is_subdomain_install()): ?>
        <input type="text" id="address" class="subdomain" value="<?php echo $address; ?>" name="address" />.<?php echo str_replace('http://', '', $sso->base_url); ?>
        <?php else: ?>
        <?php echo str_replace('http://', '', $sso->base_url); ?>/<input type="text" class="subdirectory" id="address" value="<?php echo $address; ?>" name="address" />
        <?php endif; ?>
			</p>

			<p>
				<?php if(isset($sso->error_info['site_title'])): ?>
				<span class="error"><?php echo $sso->error_info['site_title']; ?></span>
				<?php endif; ?>
				<label>Site Title</label>
				<input type="text" value="" name="site_title" id="site_title" />
			</p>

			<input type="hidden" value="<?php echo $_SESSION['callback']; ?>" name="callback" />
			<input type="hidden" value="<?php echo $sso->domain; ?>" name="domain" />
			<input type="hidden" value="gapps-setup" name="action" />

			<input type="submit" value="Done" />
	
		</form>

	</div>

</body>

</html>