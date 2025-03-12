<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

$service = &$arr[0];
$service->addMethod(
  'pwg.images.writeMetadata',
  'ws_images_writeMetadata',
  array(
    'image_id' => array('type'=>WS_TYPE_ID),
    'pwg_token' => array(),
    ),
  'Write metadata (IPTC) based on photo properties in Piwigo',
  null,
  array(
    'admin_only' => true,
    'post_only' => true
    )
);

function ws_images_writeMetadata($params, &$service)
{
  global $conf;
  
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  // does the image really exist?
  $query = '
SELECT COUNT(*)
  FROM '. IMAGES_TABLE .'
  WHERE id = '. $params['image_id'] .'
;';
  list($count) = pwg_db_fetch_row(pwg_query($query));
  if ($count == 0)
  {
    return new PwgError(404, 'image_id not found');
  }
  
  wm_write_metadata($params['image_id']);
  
  return true;
}

?>