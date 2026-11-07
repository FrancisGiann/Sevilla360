<?php
require __DIR__.'/bootstrap.php';$conn=pm_connect();
$hash=password_hash('Synthetic1!password',PASSWORD_DEFAULT);
$s=$conn->prepare("INSERT INTO users (id,email,password_hash,role) VALUES (1,'guest@example.invalid',?,'customer'),(2,'other@example.invalid',?,'customer')");$s->bind_param('ss',$hash,$hash);$s->execute();
$conn->query("INSERT INTO customers VALUES (101,1,'Synthetic','Guest','guest@example.invalid','09170000001',NULL),(202,2,'Synthetic','Other','other@example.invalid','09170000002',NULL)");

$s=$conn->prepare("INSERT INTO users (id,email,password_hash,role) VALUES (3,'staff@example.invalid',?,'staff')");$s->bind_param('s',$hash);$s->execute();
$conn->query("INSERT INTO staff (user_id,status,full_name) VALUES (3,'active','Synthetic Staff')");
