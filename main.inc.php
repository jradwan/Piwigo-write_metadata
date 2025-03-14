<?php
/*
Plugin Name: Write Metadata
Description: Write Piwigo photo properties (title, description, author, tags) into IPTC fields
Author: plg
Plugin URI: http://piwigo.org/ext/extension_view.php?eid=
Version: auto
*/

// +-----------------------------------------------------------------------+
// | Define plugin constants                                               |
// +-----------------------------------------------------------------------+

defined('WRITE_METADATA_ID') or define('WRITE_METADATA_ID', basename(dirname(__FILE__)));
define('WRITE_METADATA_PATH' , PHPWG_PLUGINS_PATH.basename(dirname(__FILE__)).'/');

// +-----------------------------------------------------------------------+
// | Edit Photo                                                            |
// +-----------------------------------------------------------------------+

add_event_handler('loc_begin_admin_page', 'wm_add_link', 60);
function wm_add_link()
{
  global $template, $page;

  $template->set_prefilter('picture_modify', 'wm_add_link_prefilter');

  if (isset($page['page']) and 'photo' == $page['page'])
  {
    $template->assign(
      'U_WRITEMETADATA',
      get_root_url().'admin.php?page=photo-'.$_GET['image_id'].'-properties&amp;write_metadata=1'
      );
  }
}

function wm_add_link_prefilter($content)
{
  $search = '{if !url_is_remote($PATH)}';
  
  $replacement = '{if !url_is_remote($PATH)}
  <a class="icon-docs" href="{$U_WRITEMETADATA}" title="{\'Write metadata\'|translate}"></a>';

  return str_replace($search, $replacement, $content);
}

add_event_handler('loc_begin_admin_page', 'wm_picture_write_metadata');
function wm_picture_write_metadata()
{
  global $page, $conf;

  load_language('plugin.lang', dirname(__FILE__).'/');
  
  if (isset($page['page']) and 'photo' == $page['page'] and isset($_GET['write_metadata']))
  {
    check_input_parameter('image_id', $_GET, false, PATTERN_ID);
    $image = get_image_infos($_GET['image_id'], true);

    list($rc, $output) = wm_write_metadata($_GET['image_id']);

    if (count($output) == 0)
    {
      $_SESSION['page_infos'][] = l10n('Metadata written into file');
      redirect(get_root_url().'admin.php?page=photo-'.$_GET['image_id'].'-properties');
    }
    else
    {
      $page['errors'] = array_merge($page['errors'], $output);
    }
  }
}

// +-----------------------------------------------------------------------+
// | Batch Manager                                                         |
// +-----------------------------------------------------------------------+

add_event_handler('loc_begin_element_set_global', 'wm_element_set_global_add_action');
function wm_element_set_global_add_action()
{
  global $template, $page;
  
  $template->set_filename('writeMetadata', realpath(WRITE_METADATA_PATH.'element_set_global_action.tpl'));

  if (isset($_POST['submit']) and $_POST['selectAction'] == 'writeMetadata')
  {
    $page['infos'][] = l10n('Metadata written into file');
  }

  $template->assign(
    array(
      'WM_PWG_TOKEN' => get_pwg_token(),
      )
    );

  $template->append(
    'element_set_global_plugins_actions',
    array(
      'ID' => 'writeMetadata',
      'NAME' => l10n('Write metadata'),
      'CONTENT' => $template->parse('writeMetadata', true),
      )
    );
}

add_event_handler('ws_add_methods', 'wm_add_methods');
function wm_add_methods($arr)
{
  include_once(WRITE_METADATA_PATH.'ws_functions.inc.php');
}

// +-----------------------------------------------------------------------+
// | Common functions                                                      |
// +-----------------------------------------------------------------------+

/**
 * inspired by convert_row_to_file_exiftool method in ExportImageMetadata
 * class from plugin Tags2File. In plugin write_medata we just skip the
 * batch command file, and execute directly on server (much more user
 * friendly...).
 */
function wm_write_metadata($image_id)
{
  global $conf, $logger;

  $has_md5sum_fs = pwg_db_num_rows(pwg_query('SHOW COLUMNS FROM `'.IMAGES_TABLE.'` LIKE "md5sum_fs" '));

  $query = '
SELECT
    img.name,
    img.comment,
    img.author,
    img.date_creation,
    GROUP_CONCAT(tags.name) AS tags,
    img.path,
    img.representative_ext
    '.($has_md5sum_fs ? ', img.md5sum_fs' : '').'
  FROM '.IMAGES_TABLE.' AS img
    LEFT OUTER JOIN '.IMAGE_TAG_TABLE.' AS img_tag ON img_tag.image_id = img.id
    LEFT OUTER JOIN '.TAGS_TABLE.' AS tags ON tags.id = img_tag.tag_id
  WHERE img.id = '.$image_id.'
  GROUP BY img.id, img.name, img.comment, img.author, img.path, img.representative_ext
;';
  $images = query2array($query);
  if (count($images) == 0)
  {
    fatal_error(__FUNCTION__." photo ".$image_id." does not exist");
  }

  $row = $images[0];

  $name = wm_prepare_string($row['name'], 256);
  $description = wm_prepare_string($row['comment'], 2000);
  $author = wm_prepare_string($row['author'], 32);
  $date_creation = wm_prepare_string($row['date_creation'], 20);

  $command = isset($conf['exiftool_path']) ? $conf['exiftool_path'] : 'exiftool';
  $command.= ' -q';

  if ($conf['write_metadata_overwrite_original'])
  {
    $command.= ' -overwrite_original';
  }

  if ($conf['write_metadata_preserve_date'])
  {
    $command.= ' -preserve';
  }

  if (strlen($name) > 0)
  {
    # 2#105 in iptcparse($imginfo['APP13'])
    $command.= ' -IPTC:Headline="'.$name.'"';

    # 2#005 in iptcparse($imginfo['APP13'])
    $command.= ' -IPTC:ObjectName="'.wm_cutString($name, 64).'"';
  }

  if (strlen($description) > 0)
  {
    # 2#120 in iptcparse($imginfo['APP13'])
    $command.= ' -IPTC:Caption-Abstract="'.$description.'"';
  }

  if (strlen($author) > 0)
  {
    # 2#080 in iptcparse($imginfo['APP13'])
    $iptc_field = 'By-line';

    if (
      $conf['use_iptc']
      and isset($conf['use_iptc_mapping']['author'])
      and '2#122' == $conf['use_iptc_mapping']['author']
      )
    {
      # 2#122 in iptcparse($imginfo['APP13'])
      $iptc_field = 'Writer-Editor';
    }

    $command.= ' -IPTC:'.$iptc_field.'="'.$author.'"';
  }

  if (strlen($date_creation) > 0)
  {
    # 2#055 in iptcparse($imginfo['APP13'])
    $command.= ' -IPTC:DateCreated="'.$date_creation.'"';
    if ($conf['write_metadata_set_exif_date'])
    {
      $command.= ' -DateTimeOriginal="'.$date_creation.'"';
    }
  }
  
  if (strlen($row['tags']) > 0)
  {
    $tags = explode(',', $row['tags']);
    foreach ($tags as $tag)
    {
      $tag = wm_prepare_string($tag, 64);

      # 2#025 in iptcparse($imginfo['APP13'])
      $command.= ' -IPTC:Keywords="'.$tag.'"';
    }
  }

  $command.= ' "'.$row['path'].'"';
  $command.= ' 2>&1';
  // echo $command;
  $logger->info(__FUNCTION__.' command = '.$command);

  $exec_return = exec($command, $output, $rc);
  // echo '$exec_return = '.$exec_return.'<br>';
  // echo '<pre>'; print_r($output); echo '</pre>';

  $activity_details = array('function' => __FUNCTION__);

  if ($has_md5sum_fs)
  {
    $md5sum = md5_file($row['path']);
    single_update(IMAGES_TABLE, array('md5sum_fs' => $md5sum), array('id' => $image_id));

    $activity_details['md5sum_fs_previous'] = $row['md5sum_fs'];
    $activity_details['md5sum_fs_new'] = $md5sum;
  }

  pwg_activity('photo', $image_id, 'edit', $activity_details);

  // as derivatives may contain metadata, they must be reset
  delete_element_derivatives($row);

  return array($rc, $output);
}

function wm_prepare_string($string, $maxLen)
{
  return wm_cutString(
    wm_explode_description(
      wm_decode_html_string_to_unicode($string)
      ),
    $maxLen
    );
}

function wm_cutString($description, $maxLen)
{
  if (strlen($description) > $maxLen)
  {
    $description = substr($description, 0, $maxLen);
  }
  return $description;
}

function wm_explode_description($description)
{
  return str_replace(
    array('<br>', '<br />', "\n", "\r"),
    array('', '', '', ''),
    $description
    );
}

function wm_decode_html_string_to_unicode($string)
{
  if (isset($string) and strlen($string) > 0)
  {
    $string = html_entity_decode(trim($string), ENT_QUOTES, 'UTF-8');
    $string = stripslashes($string);
  }
  else
  {
    $string = '';
  }
  
  return($string);
}
?>
