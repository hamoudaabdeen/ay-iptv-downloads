<?php
/**
 * Plugin Name: AY ERP v71.0.1 Deployment Bridge
 * Description: One-time guarded deployment bridge for AY ERP v71.0.1.
 * Version: 1.0.0
 * Author: AY Soft
 */
if (!defined('ABSPATH')) exit;

function ayerp7101_once($text,$old,$new,$label){
    $count=substr_count($text,$old);
    if($count!==1) throw new Exception($label.' expected once, found '.$count);
    return str_replace($old,$new,$text,$n);
}
function ayerp7101_copy_tree($src,$dst){
    if(is_link($src)){
        $target=readlink($src);
        if($target===false || !@symlink($target,$dst)) throw new Exception('Cannot copy symlink: '.$src);
        return;
    }
    if(is_dir($src)){
        if(!is_dir($dst) && !@mkdir($dst,0755,true)) throw new Exception('Cannot create dir: '.$dst);
        $items=scandir($src); if($items===false) throw new Exception('Cannot scan: '.$src);
        foreach($items as $item){ if($item==='.'||$item==='..') continue; ayerp7101_copy_tree($src.'/'.$item,$dst.'/'.$item); }
        return;
    }
    if(!@copy($src,$dst)) throw new Exception('Cannot copy file: '.$src);
    @chmod($dst,fileperms($src)&0777);
}
function ayerp7101_remove_tree($path){
    if(is_link($path)||is_file($path)){@unlink($path);return;}
    if(!is_dir($path))return;
    $items=scandir($path);
    if($items!==false)foreach($items as $item){if($item==='.'||$item==='..')continue;ayerp7101_remove_tree($path.'/'.$item);}
    @rmdir($path);
}
function ayerp7101_patch_doc($text){
$old=<<<'TXT'
  const txt=(ar,en)=>isEnglish()?en:ar;
TXT;
$new=<<<'TXT'
  const txt=(ar,en)=>isEnglish()?en:ar;
  function normalizeDocumentText(value){
    const cleaned=String(value??'')
      .replace(/\r\n?/g,'\n')
      .replace(/\u00a0/g,' ')
      .replace(/[\u200e\u200f\u202a-\u202e\u2066-\u2069]/g,'');
    return typeof cleaned.normalize==='function'?cleaned.normalize('NFC'):cleaned;
  }
  function bidiInlineHtml(value){
    const text=normalizeDocumentText(value);
    if(isEnglish())return esc(text);
    const re=/([\(\[]?\s*[A-Za-z0-9][A-Za-z0-9+%.,/:_#@ -]*(?:\s*[\)\]])?[.;:!?]?)/g;
    let html='',last=0,match;
    while((match=re.exec(text))){
      const run=match[0],nowrap=run.trim().length<=32?' aydoc-ltr-nowrap':'';
      html+=esc(text.slice(last,match.index));
      html+='<bdi class="aydoc-ltr-run'+nowrap+'" dir="ltr">'+esc(run)+'</bdi>';
      last=match.index+run.length;
    }
    return html+esc(text.slice(last));
  }
  function bidiLinesHtml(value){
    const lineDir=isEnglish()?'ltr':'rtl';
    return normalizeDocumentText(value).split('\n').map(line=>
      '<span class="aydoc-bidi-line" dir="'+lineDir+'">'+(line?bidiInlineHtml(line):'&nbsp;')+'</span>'
    ).join('');
  }
  function wordBidiLinesHtml(value){
    const textDir=isEnglish()?'ltr':'rtl',textAlign=isEnglish()?'left':'right';
    return normalizeDocumentText(value).split('\n').map(line=>
      '<div dir="'+textDir+'" style="direction:'+textDir+';text-align:'+textAlign+';unicode-bidi:embed;white-space:pre-wrap;">'+(line?esc(line):'&nbsp;')+'</div>'
    ).join('');
  }
TXT;
$text=ayerp7101_once($text,$old,$new,'helpers');
$text=ayerp7101_once($text,
'<textarea id="dcNotes" class="input textarea">${esc(doc.notes)}</textarea>',
'<textarea id="dcNotes" class="input textarea aydoc-bidi-input" dir="${isEnglish()?\'ltr\':\'rtl\'}" lang="${isEnglish()?\'en\':\'ar\'}" data-doc-bidi>${esc(doc.notes)}</textarea>','notes textarea');
$old=<<<'TXT'
    doc.vatRate=Number(document.getElementById('dcVat')?.value||0);doc.notes=document.getElementById('dcNotes')?.value||'';
TXT;
$new=<<<'TXT'
    doc.vatRate=Number(document.getElementById('dcVat')?.value||0);
    const notesField=document.getElementById('dcNotes');
    doc.notes=normalizeDocumentText(notesField?.value||'');
    if(notesField){notesField.dir=isEnglish()?'ltr':'rtl';notesField.lang=isEnglish()?'en':'ar';}
TXT;
$text=ayerp7101_once($text,$old,$new,'sync notes');
$text=ayerp7101_once($text,
"        const termsLines=String(doc.notes||'').split(/\\n+/).map(x=>x.trim()).filter(Boolean);",
"        const termsLines=normalizeDocumentText(doc.notes).split('\\n').map(x=>x.trim()).filter(Boolean);",'terms lines');
$text=ayerp7101_once($text,
"          ? '<ul>'+termsLines.map(x=>'<li>'+esc(x.replace(/^[-•]+\\s*/,''))+'</li>').join('')+'</ul>'",
"          ? '<ul>'+termsLines.map(x=>'<li dir=\"'+(isEnglish()?'ltr':'rtl')+'\"><span class=\"aydoc-bidi-line\">'+bidiInlineHtml(x.replace(/^[-•]+\\s*/,''))+'</span></li>').join('')+'</ul>'",'terms body');
$text=ayerp7101_once($text,
'${bodyPrint()}${doc.notes&&doc.template!==\'visual\'?\'<div class="invoice-notes"><b>\'+txt(\'ملاحظات\',\'Notes\')+\':</b> \'+esc(doc.notes).replace(/\\n/g,\'<br>\')+\'</div>\':\'\'}${foot}',
'${bodyPrint()}${doc.notes&&doc.template!==\'visual\'?\'<div class="invoice-notes aydoc-bidi-block" dir="\'+dir+\'"><b>\'+txt(\'ملاحظات\',\'Notes\')+\':</b><div class="aydoc-bidi-lines">\'+bidiLinesHtml(doc.notes)+\'</div></div>\':\'\'}${foot}','preview notes');
$text=ayerp7101_once($text,
"      'div.Section1{page:Section1;}body{font-family:Arial,Tahoma,sans-serif;font-size:10pt;color:#0d2441;}'+",
"      'div.Section1{page:Section1;}body{font-family:Cairo,Tahoma,Arial,sans-serif;font-size:10pt;color:#0d2441;direction:'+dir+';text-align:'+align+';}'+",'word font');
$text=ayerp7101_once($text,
"    const notes=doc.notes?'<div class=\"notes\"><b>'+txt('ملاحظات','Notes')+':</b><br>'+esc(doc.notes).replace(/\\n/g,'<br>')+'</div>':'';",
"    const notes=doc.notes?'<div class=\"notes\" dir=\"'+dir+'\" style=\"text-align:'+align+'\"><b>'+txt('ملاحظات','Notes')+':</b>'+wordBidiLinesHtml(doc.notes)+'</div>':'';",'word notes');
$text=ayerp7101_once($text,
'body>*{display:block!important}.print-only-wrap{display:block!important;width:210mm!important;margin:0!important;padding:0!important}.print-only-wrap .aydoc-sheet{display:flex!important;width:210mm!important;max-width:210mm!important;min-height:297mm!important;margin:0!important;box-shadow:none!important;border-radius:0!important;overflow:visible!important}',
'body{font-family:Cairo,Tahoma,Arial,sans-serif!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}body>*{display:block!important}.print-only-wrap{display:block!important;width:210mm!important;margin:0!important;padding:0!important}.print-only-wrap .aydoc-sheet{display:flex!important;width:210mm!important;max-width:210mm!important;min-height:297mm!important;margin:0!important;box-shadow:none!important;border-radius:0!important;overflow:visible!important}.print-only-wrap .aydoc-bidi-line{unicode-bidi:plaintext!important;white-space:pre-wrap!important}','print css');
return $text;
}
function ayerp7101_patch_styles($text){
$old='textarea.input{line-height:1.75}';
$new=<<<'TXT'
textarea.input{line-height:1.75}
/* v71.0.1 - Arabic/BiDi document text hardening */
.aydoc-bidi-input{white-space:pre-wrap!important;unicode-bidi:plaintext!important;text-align:start!important}
.aydoc-bidi-input[dir="rtl"]{direction:rtl!important;text-align:right!important}
.aydoc-bidi-input[dir="ltr"]{direction:ltr!important;text-align:left!important}
.aydoc-bidi-block{unicode-bidi:isolate!important}
.aydoc-bidi-lines{display:block!important;margin-top:4px!important}
.aydoc-bidi-line{display:block!important;unicode-bidi:plaintext!important;white-space:pre-wrap!important;overflow-wrap:anywhere!important;word-break:normal!important;text-align:start!important}
.aydoc-ltr-run{direction:ltr!important;unicode-bidi:isolate!important;display:inline!important}.aydoc-ltr-nowrap{white-space:nowrap!important}
.doc-lang-ar .invoice-notes,.doc-lang-ar .aydoc-visual-terms{direction:rtl!important;text-align:right!important}
.doc-lang-en .invoice-notes,.doc-lang-en .aydoc-visual-terms{direction:ltr!important;text-align:left!important}
.doc-lang-ar .aydoc-visual-terms li{direction:rtl!important;text-align:right!important}
.doc-lang-en .aydoc-visual-terms li{direction:ltr!important;text-align:left!important}
@media print{.aydoc-bidi-line{unicode-bidi:plaintext!important;white-space:pre-wrap!important}.aydoc-sheet{font-family:'Cairo','Segoe UI',Tahoma,Arial,sans-serif!important}}
TXT;
return ayerp7101_once($text,$old,$new,'styles');
}
function ayerp7101_patch_file($path,$rel){
$spec=array(
'public/document-center.js'=>array('old'=>'8026e3518aec4d784d292db86a04b668205c7fe7bc6e86494989ff249188e268','new'=>'5b70f30e6a1f26864c86ad337d01a4c628628a7f7e3f8737909f4492e93d8d25'),
'public/styles.css'=>array('old'=>'522703037a59386ef64434d293da999d002e0c8c071fcfb23c9db80022b1bdf4','new'=>'1ed327feb19b930fcaf31a9c13d89f29c74273528259f93d24717d4473179156'),
'public/config.js'=>array('old'=>'10937660a7b6e28757d64799dc743d33776b0c2a1ae58ef0c5825c7818d5e519','new'=>'35d39bf7f7af1ba7f324c885319b37dac870080e67f54228047b85fe02e970e6'),
'public/sw.js'=>array('old'=>'ccd4c0c348b56b7fd70ebaf1488f078cdeb7ce5a4da7d2cb07367ac1d6b9c800','new'=>'879b03988bb623eadbdc152b6e0c97a7ebaf2bbd0bc04a8247254deda0ccf3b5'),
'public/index.html'=>array('old'=>'e1e96a197f54039f0c9e22017b8a2e99b898e2777c45daad0fe3f315d0ab8b05','new'=>'f84fd8c4966673ea36945d53d76017089a493692831369657ef81ed463a4564b'),
);
if(!isset($spec[$rel]))throw new Exception('Unknown file '.$rel);
$text=@file_get_contents($path);if($text===false)throw new Exception('Cannot read '.$rel);
if(hash('sha256',$text)!==$spec[$rel]['old'])throw new Exception('Preflight hash mismatch '.$rel);
if($rel==='public/document-center.js')$text=ayerp7101_patch_doc($text);
elseif($rel==='public/styles.css')$text=ayerp7101_patch_styles($text);
elseif($rel==='public/config.js')$text=ayerp7101_once($text,'version:"71.0.0"','version:"71.0.1"','config version');
elseif($rel==='public/sw.js')$text=ayerp7101_once($text,"const CACHE='ay-erp-shell-v71-0-0';","const CACHE='ay-erp-shell-v71-0-1';",'service worker cache');
elseif($rel==='public/index.html')$text=str_replace('?v=7100','?v=7101',$text,$count);
if(hash('sha256',$text)!==$spec[$rel]['new'])throw new Exception('Post-patch hash mismatch '.$rel);
if(@file_put_contents($path,$text,LOCK_EX)===false)throw new Exception('Cannot write '.$rel);
if(hash_file('sha256',$path)!==$spec[$rel]['new'])throw new Exception('Post-write hash mismatch '.$rel);
}
function ayerp7101_self_cleanup(){
    if(function_exists('deactivate_plugins'))deactivate_plugins(plugin_basename(__FILE__),true);
    $dir=plugin_dir_path(__FILE__);
    register_shutdown_function(function()use($dir){ayerp7101_remove_tree(rtrim($dir,'/\\'));});
}
function ayerp7101_run(WP_REST_Request $request){
    if(!current_user_can('manage_options'))return new WP_Error('forbidden','Administrator required',array('status'=>403));
    @set_time_limit(240);
    $home=dirname(dirname(dirname(rtrim(ABSPATH,'/\\'))));
    $erp=$home.'/ayerp';$current=$erp.'/current';$old=realpath($current);
    if(!$old||!is_dir($old)||!is_link($current))return new WP_Error('current_missing','AY ERP current release/symlink not found',array('status'=>500));
    $cfg=@file_get_contents($old.'/public/config.js');
    if($cfg===false||strpos($cfg,'version:"71.0.0"')===false)return new WP_Error('unexpected_version','Live AY ERP is not v71.0.0; aborted',array('status'=>409,'current'=>$old));
    $rels=array('public/document-center.js','public/styles.css','public/config.js','public/sw.js','public/index.html');
    $expected=array(
      'public/document-center.js'=>'8026e3518aec4d784d292db86a04b668205c7fe7bc6e86494989ff249188e268',
      'public/styles.css'=>'522703037a59386ef64434d293da999d002e0c8c071fcfb23c9db80022b1bdf4',
      'public/config.js'=>'10937660a7b6e28757d64799dc743d33776b0c2a1ae58ef0c5825c7818d5e519',
      'public/sw.js'=>'ccd4c0c348b56b7fd70ebaf1488f078cdeb7ce5a4da7d2cb07367ac1d6b9c800',
      'public/index.html'=>'e1e96a197f54039f0c9e22017b8a2e99b898e2777c45daad0fe3f315d0ab8b05');
    foreach($rels as $rel){$p=$old.'/'.$rel;if(!is_file($p)||hash_file('sha256',$p)!==$expected[$rel])return new WP_Error('preflight_mismatch','Live file differs from expected v71.0.0: '.$rel,array('status'=>409));}
    $new=$erp.'/releases/'.gmdate('Ymd-His').'-v71.0.1';$tmp=$erp.'/current-v7101-tmp';$rollback=$erp.'/rollback-pre-v71-0-1';$switched=false;
    try{
      ayerp7101_copy_tree($old,$new);
      foreach($rels as $rel)ayerp7101_patch_file($new.'/'.$rel,$rel);
      @file_put_contents($new.'/RELEASE-71.0.1-RTL-PDF.txt',"AY ERP v71.0.1\nArabic RTL/PDF hardening\nDeployed: ".gmdate('c')."\n");
      if(is_link($tmp)||file_exists($tmp))@unlink($tmp);
      if(!@symlink($new,$tmp))throw new Exception('Cannot create temporary current symlink');
      if(is_link($rollback)||file_exists($rollback))@unlink($rollback);
      if(!@rename($current,$rollback))throw new Exception('Cannot create rollback pointer');
      if(!@rename($tmp,$current)){@rename($rollback,$current);throw new Exception('Cannot switch current symlink');}
      $switched=true;
      $checks=array('public/config.js'=>'version:"71.0.1"','public/document-center.js'=>'normalizeDocumentText','public/styles.css'=>'.aydoc-ltr-run','public/index.html'=>'?v=7101');
      foreach($checks as $rel=>$needle){$body=@file_get_contents($current.'/'.$rel);if($body===false||strpos($body,$needle)===false)throw new Exception('Current verification failed '.$rel);}
      if(has_action('litespeed_purge_all'))do_action('litespeed_purge_all');
      ayerp7101_self_cleanup();
      return rest_ensure_response(array('ok'=>true,'version'=>'71.0.1','previous'=>$old,'current'=>realpath($current),'rollback_pointer'=>$rollback,'self_cleanup'=>'scheduled'));
    }catch(Throwable $e){
      if($switched){@unlink($current);@rename($rollback,$current);if(is_dir($new))@rename($new,$new.'-failed');}elseif(is_dir($new))ayerp7101_remove_tree($new);
      if(is_link($tmp)||file_exists($tmp))@unlink($tmp);
      return new WP_Error('deploy_failed',$e->getMessage(),array('status'=>500,'rolled_back'=>$switched));
    }
}
add_action('rest_api_init',function(){register_rest_route('ay-erp-deploy/v1','/run',array('methods'=>'POST','callback'=>'ayerp7101_run','permission_callback'=>function(){return current_user_can('manage_options');}));});
