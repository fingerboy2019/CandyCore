<!DOCTYPE>
<html>	
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width, maximum-scale=1.0, initial-scale=1.0, user-scalable=no">
	<{if $timeout > 0}>
    <meta http-equiv="refresh" content="<{$timeout}>;URL=<{$location}>" />
	<{/if}>
    <title>提示消息 - Candy php</title>
    <style type="text/css">
		*{padding:0;margin:0;}
		body{background:#fff;color:#000;font-family:"Microsoft Yahei","Hiragino Sans GB","Helvetica Neue",Helvetica,tahoma,arial,"WenQuanYi Micro Hei",Verdana,sans-serif;}
		.candy-msg{width:500px;position:absolute;top:44%;left:50%;margin:-87px 0 0 -250px;line-height:30px;text-align:center;font-size:14px;background:#fff;box-shadow: 0px 0px 25px #999;border-radius: 3px;}
		.candy-msg-title{height:35px;line-height:35px;color:#fff;background:#333;}
		.candy-msg-body{margin:20px 0;text-align:center}
		.candy-info{margin-bottom:10px;}
		.candy-msg-body p{font-size:12px;}
		.candy-msg-body p a{font-size:12px;color:#333;text-decoration:none;}
		.candy-msg-body p a:hover{color:#337ab7;}
	</style>
</head>
<body>
	<div class="candy-msg">        	
		<div class="candy-msg-title">提示信息</div>
		<div class="candy-msg-body">
			<div class="candy-info <{if $mark}><{/if}>"><{$message}></div>
			<{if $timeout > 0}>
			<p>本页面将在<span style="color:red;font-weight:bold;margin:0 5px;"><{$timeout}></span>秒后跳转...</p>
			<{else}>
			<p><a href="javascript:history.back(-1)" title="点击返回上一页">点击返回上一页</a></p>
			<{/if}>
		</div>
	</div> 
</body>