<?php
$source = file_get_contents(__DIR__ . '/../Members-Only PDFs.php');
$start = strpos($source, 'function mop_upload_relative_path(');
$end = strpos($source, 'function mop_stream_file(', $start);
$builder_start = strpos($source, 'function mop_build_protected_url(');
$builder_end = strpos($source, "add_filter('image_downsize'", $builder_start);
if ($start === false || $end === false || $builder_start === false || $builder_end === false) exit("Could not find source functions\n");
class WP_Error { private $code; public function __construct($code, $message) {$this->code=$code;} public function get_error_code(){return $this->code;} }
function is_wp_error($value){return $value instanceof WP_Error;}
function wp_normalize_path($path){return str_replace('\\', '/', $path);}
function trailingslashit($path){return rtrim($path, '/') . '/';}
function wp_basename($path){return basename($path);}
function wp_get_upload_dir(){return ['basedir' => $GLOBALS['uploads'], 'baseurl' => 'https://example.test/wp-content/uploads'];}
function get_the_guid($id){return $GLOBALS['guid'][$id] ?? '';}
function wp_mkdir_p($path){return is_dir($path) || mkdir($path, 0777, true);}
function mop_ensure_htaccess(){return true;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['post_meta'][$id][$key] ?? '';}
function update_post_meta($id,$key,$value){$GLOBALS['post_meta'][$id][$key]=$value; return true;}
function delete_post_meta($id,$key){unset($GLOBALS['post_meta'][$id][$key]); return true;}
function get_attached_file($id,$unfiltered=true){return $GLOBALS['attached'][$id] ?? false;}
function update_attached_file($id,$path){$GLOBALS['attached'][$id]=$path; return true;}
function wp_get_attachment_metadata($id,$unfiltered=false){return $GLOBALS['attachment_meta'][$id] ?? false;}
function wp_update_attachment_metadata($id,$meta){$GLOBALS['attachment_meta'][$id]=$meta; return true;}
function home_url($path){return 'https://example.test' . $path;}
function add_filter($name,$callback){$GLOBALS['filters'][$name]=$callback;}
function mop_is_image($id){return false;}
function mop_is_pdf($id){return true;}
function image_get_intermediate_size($id,$size){return false;}
$root = sys_get_temp_dir() . '/mop-filename-check-' . bin2hex(random_bytes(6));
$GLOBALS['uploads'] = $root . '/uploads';
define('MOP_DIR', $GLOBALS['uploads'] . '/members-only');
define('MOP_META_ORIG', '_mop_orig_filename');
define('MOP_META_ORIG_PATH', '_mop_orig_relative_path');
define('MOP_META_SIDECARS', '_mop_protected_sidecars');
define('MOP_META_PROTECT', '_mop_members_only');
mkdir($GLOBALS['uploads'] . '/2026/11', 0777, true);
mkdir(MOP_DIR);
eval(substr($source, $start, $end - $start));
eval(substr($source, $builder_start, $builder_end - $builder_start));
$assert = static function($ok,$name){if (!$ok) {fwrite(STDERR,"FAIL: $name\n"); exit(1);} echo "PASS: $name\n";};
$id=12419;
$original = $GLOBALS['uploads'] . '/2026/11/seniority-roster-2026-11-04.pdf';
$preview = $GLOBALS['uploads'] . '/2026/11/seniority-roster-2026-11-04-pdf.jpg';
file_put_contents($original, 'pdf test bytes');
file_put_contents($preview, 'preview test bytes');
$GLOBALS['attached'][$id]=$original;
$GLOBALS['attachment_meta'][$id]=['file'=>'2026/11/seniority-roster-2026-11-04.pdf','sizes'=>['full'=>['file'=>basename($preview),'width'=>200,'height'=>300]]];
$GLOBALS['guid'][$id]='https://example.test/wp-content/uploads/2026/11/seniority-roster-2026-11-04.pdf';
$result=mop_protect_attachment_files($id,$original);
$locked=$GLOBALS['attached'][$id];
$map=get_post_meta($id,MOP_META_SIDECARS,true);
$assert($result===true && is_file($locked) && mop_is_opaque_protected_name($locked) && !file_exists($original),'new file gets random storage name');
$assert(count($map)===1 && is_file(MOP_DIR.'/'.array_key_first($map)) && !file_exists($preview),'preview gets random storage name');
$assert(get_post_meta($id,MOP_META_ORIG_PATH,true)==='2026/11/seniority-roster-2026-11-04.pdf','original relative path saved');
$assert(mop_build_protected_url($id)==='https://example.test/members-file/12419/','main link contains no name');
$assert(mop_locked_download_name($locked,$id)==='protected-file-12419.pdf','locked download suggests generic name');
$assert(strpos(mop_build_protected_url($id,basename($preview)),basename($preview))===false,'preview link contains no name');
$GLOBALS['post_meta'][$id][MOP_META_PROTECT]='1';
$filter_start = strpos($source, "add_filter('image_downsize'");
$filter_end = strpos($source, "add_filter('wp_calculate_image_srcset'", $filter_start);
eval(substr($source,$filter_start,$filter_end-$filter_start));
$pdf_preview = $GLOBALS['filters']['image_downsize'](false,$id,'full');
$assert($pdf_preview[0]===mop_build_protected_url($id,basename($preview)) && $pdf_preview[1]===200 && $pdf_preview[2]===300,'PDF preview uses private route');
$assert(mop_resolve_requested_protected_file($id,$locked,'')['path']===$locked && mop_resolve_requested_protected_file($id,$locked,basename($original))['path']===$locked,'new and legacy main routes resolve');
$assert(mop_resolve_requested_protected_file($id,$locked,basename($preview))['path']===MOP_DIR.'/'.array_key_first($map),'legacy preview route resolves to private file');
$result=mop_unprotect_attachment_files($id,$locked);
$assert($result===true && $GLOBALS['attached'][$id]===$original && is_file($original) && is_file($preview),'unlock restores original file and preview paths');
rename($original,MOP_DIR.'/'.basename($original));
rename($preview,MOP_DIR.'/'.basename($preview));
$GLOBALS['attached'][$id]=MOP_DIR.'/'.basename($original);
$GLOBALS['post_meta'][$id][MOP_META_SIDECARS]=[basename($preview)=>basename($preview)];
unset($GLOBALS['post_meta'][$id][MOP_META_ORIG_PATH], $GLOBALS['attachment_meta'][$id]['file']);
$result=mop_protect_attachment_files($id,$GLOBALS['attached'][$id]);
$locked=$GLOBALS['attached'][$id];
$map=get_post_meta($id,MOP_META_SIDECARS,true);
$assert($result===true && mop_is_opaque_protected_name($locked) && !file_exists(MOP_DIR.'/'.basename($original)) && !file_exists(MOP_DIR.'/'.basename($preview)) && mop_is_opaque_protected_name(array_key_first($map)),'legacy protected file and preview migrate');
$assert(get_post_meta($id,MOP_META_ORIG_PATH,true)==='2026/11/seniority-roster-2026-11-04.pdf','legacy original path recovered from GUID');
$assert(mop_unprotect_attachment_files($id,$locked)===true && is_file($original) && is_file($preview),'migrated file unlocks to original paths');
$assert(mop_protect_attachment_files($id,$original)===true,'file can be protected again');
$locked=$GLOBALS['attached'][$id];
file_put_contents($original,'collision');
$result=mop_unprotect_attachment_files($id,$locked);
$assert(is_wp_error($result) && $result->get_error_code()==='mop_original_path_occupied' && is_file($locked) && file_get_contents($original)==='collision','unlock refuses to overwrite occupied original path');
unlink($original);
$assert(mop_unprotect_attachment_files($id,$locked)===true,'unlock succeeds after collision removed');
echo "All filename checks passed\n";
