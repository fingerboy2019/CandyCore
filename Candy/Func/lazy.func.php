<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

function extractZip(string $path, string $root = ''): bool
{
    return \Candy\Core\Trait\Func\FuncLoader::forward(__FUNCTION__, func_get_args());
}

function makeZip(string $path,array $files,string $root = ''): bool
{
    return \Candy\Core\Trait\Func\FuncLoader::forward(__FUNCTION__, func_get_args());
}

function getLocalIP(string $host = ''): string
{
    return \Candy\Core\Trait\Func\FuncLoader::forward(__FUNCTION__, func_get_args());
}

function getMirContents(string $url,array $config): array
{
    return \Candy\Core\Trait\Func\FuncLoader::forward(__FUNCTION__, func_get_args());
}

function setMirContents(string $url,array $content, $config): void
{
    \Candy\Core\Trait\Func\FuncLoader::forward(__FUNCTION__, func_get_args());
}

function makeMirForSave($url, $config = []): void
{
    \Candy\Core\Trait\Func\FuncLoader::forward(__FUNCTION__, func_get_args());
}

function rpc(string $serverName, array $config = []): object
{
    return \Candy\Core\Trait\Func\FuncLoader::forward(__FUNCTION__, func_get_args());
}

function verificationCode(string $code = '',string $key = '',int $height=27,int $width=80): void
{
    \Candy\Core\Trait\Func\FuncLoader::forward(__FUNCTION__, func_get_args());
}
