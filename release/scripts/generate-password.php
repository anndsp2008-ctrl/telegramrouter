<?php $password=$argv[1]??null; if(!$password){fwrite(STDERR,"Uso: php scripts/generate-password.php 'senha'\n");exit(1);} echo password_hash($password,PASSWORD_DEFAULT).PHP_EOL;
