<?php
return [
  'submitted' => ['received', 'rejected'],
  'received' => ['validation', 'rejected'],
  'validation' => ['processing', 'waiting_client', 'rejected'],
  'waiting_client' => ['validation', 'processing', 'rejected'],
  'processing' => ['review', 'waiting_client', 'rejected'],
  'review' => ['approved', 'waiting_client', 'rejected'],
  'approved' => ['released'],
  'released' => ['completed'],
  'completed' => [],
  'rejected' => [],
];
