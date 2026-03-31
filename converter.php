<?php
declare(strict_types=1);
/**
 * WebP Image Converter v2.0
 * PHP 8.3 | GD Library
 * Full-featured: scan, convert, preview, upload, LQIP, config gen, CSV export
 */

// ─── Config ──────────────────────────────────────────────────────────
const DEFAULT_QUALITY   = 80;
const MAX_QUALITY       = 100;
const MIN_QUALITY       = 1;
const MAX_FILESIZE_MB   = 50;
const ALLOWED_MIME      = ['image/jpeg','image/png','image/webp','image/gif'];
const CONVERTABLE_MIME  = ['image/jpeg','image/png','image/gif'];
const OUTPUT_DIR_NAME   = 'webp_output';
const UPLOAD_DIR        = __DIR__ . DIRECTORY_SEPARATOR . 'webp_uploads';
const LQIP_SIZE         = 20;

// ─── Helpers ─────────────────────────────────────────────────────────
function formatBytes(int $bytes, int $precision = 2): string {
    if ($bytes === 0) return '0 B';
    $units = ['B','KB','MB','GB'];
    $pow = min((int)floor(log($bytes, 1024)), count($units)-1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}
function getMimeType(string $p): string|false {
    if (!file_exists($p)) return false;
    return (new finfo(FILEINFO_MIME_TYPE))->file($p);
}
function ensureDir(string $d): bool {
    return is_dir($d) || mkdir($d, 0755, true);
}
function fixExifOrientation(GdImage $image, string $path, string $mime): GdImage {
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return $image;
    $exif = @exif_read_data($path);
    if (!$exif || !isset($exif['Orientation'])) return $image;
    return match ((int)$exif['Orientation']) {
        2 => (function($i){imageflip($i,IMG_FLIP_HORIZONTAL);return $i;})($image),
        3 => imagerotate($image, 180, 0),
        4 => (function($i){imageflip($i,IMG_FLIP_VERTICAL);return $i;})($image),
        5 => (function($i){$i=imagerotate($i,270,0);imageflip($i,IMG_FLIP_HORIZONTAL);return $i;})($image),
        6 => imagerotate($image, 270, 0),
        7 => (function($i){$i=imagerotate($i,90,0);imageflip($i,IMG_FLIP_HORIZONTAL);return $i;})($image),
        8 => imagerotate($image, 90, 0),
        default => $image,
    };
}
function loadImage(string $path, string $mime): GdImage|false {
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/gif'  => @imagecreatefromgif($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => false,
    };
}
function generateLQIP(string $path, string $mime): ?string {
    $image = loadImage($path, $mime);
    if (!$image) return null;
    $image = fixExifOrientation($image, $path, $mime);
    $w = imagesx($image); $h = imagesy($image);
    $ratio = min(LQIP_SIZE/$w, LQIP_SIZE/$h);
    $nw = max(1,(int)round($w*$ratio)); $nh = max(1,(int)round($h*$ratio));
    $tiny = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($tiny, $image, 0,0,0,0, $nw,$nh, $w,$h);
    imagedestroy($image);
    ob_start();
    imagewebp($tiny, null, 20);
    $data = ob_get_clean();
    imagedestroy($tiny);
    return 'data:image/webp;base64,' . base64_encode($data);
}

// ─── Scanner ─────────────────────────────────────────────────────────
function scanImages(string $dir, int $maxSizeMB = MAX_FILESIZE_MB): array {
    $results = [];
    if (!is_dir($dir)) return $results;
    $maxBytes = $maxSizeMB * 1024 * 1024;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getRealPath();
        $size = $file->getSize();
        $mime = getMimeType($path);
        if (!$mime || !in_array($mime, ALLOWED_MIME, true)) continue;
        $isConvertable = in_array($mime, CONVERTABLE_MIME, true);
        $dims = @getimagesize($path);
        $rel = ltrim(str_replace($dir, '', $path), DIRECTORY_SEPARATOR);
        $folder = dirname($rel);
        if ($folder === '.') $folder = '';
        $results[] = [
            'path'        => $path,
            'relative'    => $rel,
            'folder'      => $folder,
            'name'        => $file->getFilename(),
            'size'        => $size,
            'size_human'  => formatBytes($size),
            'mime'        => $mime,
            'convertable' => $isConvertable && $size <= $maxBytes,
            'is_webp'     => $mime === 'image/webp',
            'too_large'   => $size > $maxBytes,
            'dimensions'  => $dims ? $dims[0].'x'.$dims[1] : 'N/A',
            'width'       => $dims ? $dims[0] : 0,
            'height'      => $dims ? $dims[1] : 0,
            'exif_orient' => ($mime==='image/jpeg' && function_exists('exif_read_data'))
                             ? (int)(@exif_read_data($path)['Orientation'] ?? 1) : 1,
        ];
    }
    usort($results, fn($a,$b) => $a['relative'] <=> $b['relative']);
    return $results;
}

// ─── Converter ───────────────────────────────────────────────────────
function convertToWebp(string $src, string $dst, int $quality=DEFAULT_QUALITY, bool $strip=true, ?int $maxW=null): array {
    $r = ['success'=>false,'source'=>$src,'dest'=>$dst,'size_before'=>0,'size_after'=>0,'saved'=>0,'saved_pct'=>0,'error'=>null,'skipped'=>false];
    try {
        if (!file_exists($src)) throw new RuntimeException("Файл не найден: $src");
        $mime = getMimeType($src);
        if (!$mime || !in_array($mime, CONVERTABLE_MIME, true)) throw new RuntimeException("Неподдерживаемый формат: $mime");
        $r['size_before'] = filesize($src);
        $image = loadImage($src, $mime);
        if (!$image) throw new RuntimeException("Не удалось прочитать изображение");
        $image = fixExifOrientation($image, $src, $mime);
        if ($mime==='image/png'||$mime==='image/gif') {
            imagepalettetotruecolor($image); imagealphablending($image,true); imagesavealpha($image,true);
        }
        if ($maxW !== null && $maxW > 0 && imagesx($image) > $maxW) {
            $oW=imagesx($image); $oH=imagesy($image);
            $nH=(int)round($oH*($maxW/$oW));
            $res=imagecreatetruecolor($maxW,$nH);
            imagealphablending($res,false); imagesavealpha($res,true);
            imagecopyresampled($res,$image,0,0,0,0,$maxW,$nH,$oW,$oH);
            imagedestroy($image); $image=$res;
        }
        ensureDir(dirname($dst));
        if (!imagewebp($image, $dst, $quality)) throw new RuntimeException("imagewebp() failed");
        imagedestroy($image);
        $after = filesize($dst);
        if ($after >= $r['size_before']) $r['warning'] = 'WebP больше оригинала';
        $r['success']=true; $r['size_after']=$after;
        $r['saved']=$r['size_before']-$after;
        $r['saved_pct']=$r['size_before']>0 ? round(($r['size_before']-$after)/$r['size_before']*100,1) : 0;
    } catch (Throwable $e) { $r['error']=$e->getMessage(); }
    return $r;
}

// ─── GET endpoints ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='GET') {
    // Thumbnail
    if (isset($_GET['thumb'])) {
        $path=realpath($_GET['thumb']);
        if (!$path||!file_exists($path)){http_response_code(404);exit;}
        $mime=getMimeType($path);
        if (!$mime||!in_array($mime,ALLOWED_MIME,true)){http_response_code(403);exit;}
        $sz=min(200,max(40,(int)($_GET['size']??80)));
        $image=loadImage($path,$mime);
        if(!$image){http_response_code(500);exit;}
        $image=fixExifOrientation($image,$path,$mime);
        $oW=imagesx($image);$oH=imagesy($image);
        if($oW>$oH){$nW=$sz;$nH=max(1,(int)round($oH*($sz/$oW)));}
        else{$nH=$sz;$nW=max(1,(int)round($oW*($sz/$oH)));}
        $t=imagecreatetruecolor($nW,$nH);
        imagealphablending($t,false);imagesavealpha($t,true);
        $tr=imagecolorallocatealpha($t,0,0,0,127);imagefill($t,0,0,$tr);
        imagecopyresampled($t,$image,0,0,0,0,$nW,$nH,$oW,$oH);
        imagedestroy($image);
        header('Content-Type: image/webp');header('Cache-Control: public, max-age=3600');
        imagewebp($t,null,70);imagedestroy($t);exit;
    }
    // Full preview
    if (isset($_GET['preview'])) {
        $path=realpath($_GET['preview']);
        if(!$path||!file_exists($path)){http_response_code(404);exit;}
        $mime=getMimeType($path);
        if(!$mime||!in_array($mime,ALLOWED_MIME,true)){http_response_code(403);exit;}
        $image=loadImage($path,$mime);
        if(!$image){http_response_code(500);exit;}
        $image=fixExifOrientation($image,$path,$mime);
        $oW=imagesx($image);$oH=imagesy($image);$mx=1600;
        if($oW>$mx||$oH>$mx){
            $ratio=min($mx/$oW,$mx/$oH);$nW=max(1,(int)round($oW*$ratio));$nH=max(1,(int)round($oH*$ratio));
            $res=imagecreatetruecolor($nW,$nH);imagealphablending($res,false);imagesavealpha($res,true);
            imagecopyresampled($res,$image,0,0,0,0,$nW,$nH,$oW,$oH);imagedestroy($image);$image=$res;
        }
        header('Content-Type: image/webp');header('Cache-Control: public, max-age=3600');
        imagewebp($image,null,85);imagedestroy($image);exit;
    }
    // Trial preview
    if (isset($_GET['trial'])) {
        $hash=preg_replace('/[^a-f0-9]/','', $_GET['trial']);
        $f=sys_get_temp_dir().DIRECTORY_SEPARATOR.'webp_converter_trial'.DIRECTORY_SEPARATOR.$hash.'.webp';
        if(!file_exists($f)){http_response_code(404);exit;}
        header('Content-Type: image/webp');header('Cache-Control: no-cache');readfile($f);exit;
    }
    // CSV export
    if (isset($_GET['export_csv'])) {
        $data = json_decode(base64_decode($_GET['export_csv']), true);
        if (!$data) { http_response_code(400); exit; }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="webp_report_'.date('Y-m-d_His').'.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['File','Status','Size Before','Size After','Saved %','Error']);
        foreach ($data as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
}

// ─── POST API ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try { match ($_POST['action']) {

    'scan' => (function(){
        $dir=realpath($_POST['directory']??'.');
        if(!$dir||!is_dir($dir)){echo json_encode(['error'=>'Директория не найдена: '.($_POST['directory']??'')]);return;}
        $images=scanImages($dir,(int)($_POST['max_filesize']??MAX_FILESIZE_MB));
        $totalSize=array_sum(array_column($images,'size'));
        $conv=array_filter($images,fn($i)=>$i['convertable']);
        $webp=count(array_filter($images,fn($i)=>$i['is_webp']));
        $skip=count(array_filter($images,fn($i)=>$i['too_large']));
        echo json_encode(['success'=>true,'directory'=>$dir,'files'=>$images,
            'stats'=>['total'=>count($images),'convertable'=>count($conv),'already_webp'=>$webp,'too_large'=>$skip,'total_size'=>formatBytes($totalSize)]]);
    })(),

    'convert' => (function(){
        $dir=realpath($_POST['directory']??'.');
        if(!$dir||!is_dir($dir)){echo json_encode(['error'=>'Директория не найдена']);return;}
        $quality=max(MIN_QUALITY,min(MAX_QUALITY,(int)($_POST['quality']??DEFAULT_QUALITY)));
        $mode=$_POST['mode']??'separate';
        $outputDir=$_POST['output_dir']??$dir.DIRECTORY_SEPARATOR.OUTPUT_DIR_NAME;
        $skipExisting=($_POST['skip_existing']??'0')==='1';
        $stripMeta=($_POST['strip_metadata']??'1')==='1';
        $maxWidth=!empty($_POST['max_width'])?(int)$_POST['max_width']:null;
        $maxSize=(int)($_POST['max_filesize']??MAX_FILESIZE_MB);
        $genLQIP=($_POST['generate_lqip']??'0')==='1';
        $selectedFiles=!empty($_POST['files'])?json_decode($_POST['files'],true):null;
        $images=scanImages($dir,$maxSize);
        $convertable=array_filter($images,fn($i)=>$i['convertable']);
        if($selectedFiles!==null)$convertable=array_filter($convertable,fn($i)=>in_array($i['relative'],$selectedFiles,true));
        $results=[];$totalBefore=0;$totalAfter=0;$converted=0;$errors=0;$skippedCount=0;
        foreach($convertable as $img){
            $rel=$img['relative'];
            $webpRel=preg_replace('/\.(jpe?g|png|gif)$/i','.webp',$rel);
            $destPath=$mode==='replace'
                ? preg_replace('/\.(jpe?g|png|gif)$/i','.webp',$img['path'])
                : rtrim($outputDir,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$webpRel;
            if($skipExisting&&file_exists($destPath)){$skippedCount++;$results[]=['file'=>$rel,'skipped'=>true,'reason'=>'Уже существует'];continue;}
            $res=convertToWebp($img['path'],$destPath,$quality,$stripMeta,$maxWidth);
            $res['file']=$rel;
            if($res['success']){
                $converted++;$totalBefore+=$res['size_before'];$totalAfter+=$res['size_after'];
                if($mode==='replace'&&$destPath!==$img['path']){@unlink($img['path']);$res['original_deleted']=true;}
                if($genLQIP){$lqip=generateLQIP($destPath,'image/webp');if($lqip)$res['lqip']=$lqip;}
            } else $errors++;
            $results[]=$res;
        }
        echo json_encode(['success'=>true,'results'=>$results,'summary'=>[
            'converted'=>$converted,'errors'=>$errors,'skipped'=>$skippedCount,
            'size_before'=>formatBytes($totalBefore),'size_after'=>formatBytes($totalAfter),
            'saved'=>formatBytes(max(0,$totalBefore-$totalAfter)),
            'saved_pct'=>$totalBefore>0?round(($totalBefore-$totalAfter)/$totalBefore*100,1):0,
        ]]);
    })(),

    'check' => (function(){
        $gd=function_exists('gd_info')?gd_info():null;
        echo json_encode(['gd_loaded'=>extension_loaded('gd'),'gd_info'=>$gd,
            'webp_support'=>$gd['WebP Support']??false,'exif_support'=>function_exists('exif_read_data'),
            'php_version'=>PHP_VERSION,'memory_limit'=>ini_get('memory_limit'),
            'max_upload'=>ini_get('upload_max_filesize'),'cwd'=>getcwd()]);
    })(),

    'trial_batch' => (function(){
        $files=!empty($_POST['files'])?json_decode($_POST['files'],true):[];
        if(empty($files)){echo json_encode(['error'=>'Нет файлов']);return;}
        $files=array_slice($files,0,12);
        $quality=max(MIN_QUALITY,min(MAX_QUALITY,(int)($_POST['quality']??DEFAULT_QUALITY)));
        $maxWidth=!empty($_POST['max_width'])?(int)$_POST['max_width']:null;
        $tmpDir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'webp_converter_trial';ensureDir($tmpDir);
        $results=[];$tB=0;$tA=0;
        foreach($files as $fp){
            $filePath=realpath($fp);if(!$filePath||!file_exists($filePath))continue;
            $mime=getMimeType($filePath);if(!$mime||!in_array($mime,CONVERTABLE_MIME,true))continue;
            $hash=md5($filePath.$quality.($maxWidth??0));
            $r=convertToWebp($filePath,$tmpDir.DIRECTORY_SEPARATOR.$hash.'.webp',$quality,true,$maxWidth);
            $r['trial_id']=$hash;$r['original_path']=$filePath;$r['name']=basename($filePath);
            if($r['success']){$tB+=$r['size_before'];$tA+=$r['size_after'];}
            $results[]=$r;
        }
        echo json_encode(['success'=>true,'results'=>$results,'summary'=>['total'=>count($results),
            'size_before'=>$tB,'size_after'=>$tA,'saved_pct'=>$tB>0?round(($tB-$tA)/$tB*100,1):0]]);
    })(),

    'trial_clean' => (function(){
        $d=sys_get_temp_dir().DIRECTORY_SEPARATOR.'webp_converter_trial';$c=0;
        if(is_dir($d))foreach(glob($d.'/*.webp')as$f){@unlink($f);$c++;}
        echo json_encode(['success'=>true,'cleaned'=>$c]);
    })(),

    'upload' => (function(){
        if(empty($_FILES['images'])){echo json_encode(['error'=>'Нет файлов']);return;}
        ensureDir(UPLOAD_DIR);
        $uploaded=[];$errs=[];
        $files=$_FILES['images'];
        $count=is_array($files['name'])?count($files['name']):1;
        for($i=0;$i<$count;$i++){
            $name=is_array($files['name'])?$files['name'][$i]:$files['name'];
            $tmp=is_array($files['tmp_name'])?$files['tmp_name'][$i]:$files['tmp_name'];
            $err=is_array($files['error'])?$files['error'][$i]:$files['error'];
            if($err!==UPLOAD_ERR_OK){$errs[]="$name: upload error $err";continue;}
            $mime=getMimeType($tmp);
            if(!$mime||!in_array($mime,CONVERTABLE_MIME,true)){$errs[]="$name: формат не поддерживается";continue;}
            $dest=UPLOAD_DIR.DIRECTORY_SEPARATOR.$name;
            $c=1;while(file_exists($dest)){$dest=UPLOAD_DIR.DIRECTORY_SEPARATOR.pathinfo($name,PATHINFO_FILENAME)."_$c.".pathinfo($name,PATHINFO_EXTENSION);$c++;}
            move_uploaded_file($tmp,$dest);
            $uploaded[]=['name'=>basename($dest),'path'=>$dest,'size'=>filesize($dest),'size_human'=>formatBytes(filesize($dest))];
        }
        echo json_encode(['success'=>true,'uploaded'=>$uploaded,'errors'=>$errs,'upload_dir'=>UPLOAD_DIR]);
    })(),

    'generate_config' => (function(){
        $type=$_POST['type']??'nginx';
        $baseUrl=$_POST['base_url']??'/images';
        if($type==='nginx'){
            $conf = "# Nginx WebP auto-serve\n# Добавить в server {} блок\n\nmap \$http_accept \$webp_suffix {\n    default   \"\";\n    \"~*webp\"  \".webp\";\n}\n\nlocation ~* ^({$baseUrl}/.+)\\.(jpe?g|png|gif)\$ {\n    set \$img_path \$1;\n    add_header Vary Accept;\n    try_files \$img_path\$webp_suffix \$uri =404;\n}\n";
        } else {
            $conf = "# Apache .htaccess WebP auto-serve\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteCond %{HTTP_ACCEPT} image/webp\n  RewriteCond %{REQUEST_FILENAME} (.+)\\.(jpe?g|png|gif)\$\n  RewriteCond %1.webp -f\n  RewriteRule (.+)\\.(jpe?g|png|gif)\$ \$1.webp [T=image/webp,L]\n</IfModule>\n\n<IfModule mod_headers.c>\n  <FilesMatch \"\\.(jpe?g|png|gif)\$\">\n    Header append Vary Accept\n  </FilesMatch>\n</IfModule>\n\nAddType image/webp .webp\n";
        }
        echo json_encode(['success'=>true,'config'=>$conf,'type'=>$type]);
    })(),

    default => (function(){echo json_encode(['error'=>'Неизвестное действие']);})(),
    }; } catch (Throwable $e) {
        echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}
// ─── HTML ────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WebP Converter v2</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
--bg-primary:#0a0a0f;--bg-secondary:#12121a;--bg-card:#16161f;--bg-card-hover:#1c1c28;--bg-input:#0e0e16;
--border:#2a2a3a;--border-active:#5b5bf0;
--text-primary:#e8e8f0;--text-secondary:#8888a0;--text-muted:#555568;
--accent:#6c5ce7;--accent-bright:#7c6cf7;--accent-glow:rgba(108,92,231,.25);
--green:#00d68f;--green-glow:rgba(0,214,143,.15);
--red:#ff6b6b;--red-glow:rgba(255,107,107,.15);
--yellow:#ffd43b;--yellow-glow:rgba(255,212,59,.12);
--cyan:#22d3ee;
--radius:12px;--radius-sm:8px;--transition:.2s cubic-bezier(.4,0,.2,1);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Outfit',sans-serif;background:var(--bg-primary);color:var(--text-primary);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(ellipse 600px 400px at 20% 20%,rgba(108,92,231,.06),transparent),radial-gradient(ellipse 500px 500px at 80% 80%,rgba(0,214,143,.04),transparent);pointer-events:none;z-index:0;animation:bgDrift 30s ease-in-out infinite alternate}
@keyframes bgDrift{0%{transform:translate(0,0)}100%{transform:translate(-3%,-2%) rotate(3deg)}}
.app-wrapper{position:relative;z-index:1;max-width:1200px;margin:0 auto;padding:40px 24px}
.app-header{text-align:center;margin-bottom:48px}
.logo-mark{display:inline-flex;align-items:center;gap:14px;margin-bottom:16px}
.logo-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--accent),#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 0 32px var(--accent-glow)}
.logo-text{font-size:32px;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--text-primary),var(--accent-bright));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo-sub{font-size:15px;color:var(--text-secondary);font-weight:300;letter-spacing:.5px}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:28px;margin-bottom:24px;transition:border-color var(--transition)}.card:hover{border-color:#33334a}
.card-title{font-size:16px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;gap:10px}
.card-title .icon{width:32px;height:32px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.icon-folder{background:var(--accent-glow)}.icon-settings{background:var(--yellow-glow)}.icon-files{background:var(--green-glow)}.icon-results{background:rgba(34,211,238,.12)}.icon-upload{background:rgba(108,92,231,.15)}
.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group label{font-size:13px;font-weight:500;color:var(--text-secondary);letter-spacing:.3px}
input[type="text"],input[type="number"],select{font-family:'JetBrains Mono',monospace;font-size:13px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input);color:var(--text-primary);outline:none;transition:all var(--transition);width:100%}
input:focus,select:focus{border-color:var(--border-active);box-shadow:0 0 0 3px var(--accent-glow)}
select{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238888a0' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:36px}
.toggle-row{display:flex;flex-wrap:wrap;gap:20px;margin-top:8px}
.toggle-label{display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;color:var(--text-secondary);user-select:none}
.toggle-label input{display:none}
.toggle-switch{width:38px;height:22px;border-radius:11px;background:var(--border);position:relative;transition:background var(--transition);flex-shrink:0}
.toggle-switch::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:var(--text-muted);transition:all var(--transition)}
.toggle-label input:checked+.toggle-switch{background:var(--accent)}
.toggle-label input:checked+.toggle-switch::after{left:19px;background:#fff}
.btn-row{display:flex;gap:12px;margin-top:8px;flex-wrap:wrap}
.btn{font-family:'Outfit',sans-serif;font-size:14px;font-weight:600;padding:11px 24px;border:none;border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition);display:inline-flex;align-items:center;gap:8px;letter-spacing:.2px}
.btn:active{transform:scale(.97)}
.btn-primary{background:linear-gradient(135deg,var(--accent),#8b5cf6);color:#fff;box-shadow:0 2px 16px var(--accent-glow)}
.btn-primary:hover{box-shadow:0 4px 24px rgba(108,92,231,.4);transform:translateY(-1px)}
.btn-primary:disabled{opacity:.4;cursor:not-allowed;transform:none}
.btn-secondary{background:var(--bg-input);color:var(--text-secondary);border:1px solid var(--border)}
.btn-secondary:hover{border-color:var(--text-muted);color:var(--text-primary)}
.btn-accent{background:var(--bg-input);border:1px solid var(--accent);color:var(--accent-bright)}
.btn-accent:hover{background:var(--accent-glow)}
.stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.stat-item{background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;text-align:center}
.stat-value{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;line-height:1.2}
.stat-label{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px}
.filter-bar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:center}
.filter-bar select{font-size:12px;padding:7px 10px;min-width:140px}
.filter-bar .filter-label{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px}
.filter-count{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text-muted);margin-left:auto}
.folder-group-header{background:var(--bg-secondary);cursor:pointer;user-select:none}
.folder-group-header td{padding:8px 14px!important;font-size:12px;font-weight:600;color:var(--text-secondary);font-family:'Outfit',sans-serif}
.folder-group-header:hover td{background:var(--bg-card-hover)}
.folder-group-header .arrow{display:inline-block;transition:transform .2s;font-size:10px;margin-right:4px}
.folder-group-header.collapsed .arrow{transform:rotate(-90deg)}
.folder-count{font-family:'JetBrains Mono',monospace;color:var(--text-muted);font-weight:400;float:right}
.files-table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:var(--radius-sm);max-height:600px;overflow-y:auto}
.files-table{width:100%;border-collapse:collapse;font-size:13px}
.files-table thead{position:sticky;top:0;z-index:2}
.files-table th{background:var(--bg-secondary);padding:10px 14px;text-align:left;font-weight:600;color:var(--text-secondary);font-size:11px;text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--border)}
.files-table td{padding:9px 14px;border-bottom:1px solid rgba(42,42,58,.5);font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text-secondary);white-space:nowrap}
.files-table tr:hover td{background:var(--bg-card-hover)}
.fname{color:var(--text-primary);max-width:400px;overflow:hidden;text-overflow:ellipsis}.fpath{color:var(--text-muted);font-size:11px}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.badge-ok{background:var(--green-glow);color:var(--green)}.badge-skip{background:var(--yellow-glow);color:var(--yellow)}.badge-err{background:var(--red-glow);color:var(--red)}.badge-webp{background:rgba(34,211,238,.12);color:var(--cyan)}
.td-thumb{width:52px;padding:5px 8px!important}
.thumb-img{width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--border);cursor:pointer;transition:all var(--transition);background:var(--bg-input);display:block}
.thumb-img:hover{border-color:var(--accent);box-shadow:0 0 12px var(--accent-glow);transform:scale(1.08)}
.progress-wrap{margin:20px 0}
.progress-bar-track{height:8px;background:var(--bg-input);border-radius:4px;overflow:hidden;border:1px solid var(--border)}
.progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--green));border-radius:4px;transition:width .4s ease;width:0%;position:relative}
.progress-bar-fill::after{content:'';position:absolute;right:0;top:0;width:60px;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.2));animation:shimmer 1.5s infinite}
@keyframes shimmer{0%{opacity:0}50%{opacity:1}100%{opacity:0}}
.progress-info{display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--text-muted);font-family:'JetBrains Mono',monospace}
.result-log{background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;max-height:400px;overflow-y:auto;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.8}
.log-line{display:flex;gap:10px;align-items:baseline}.log-ok{color:var(--green)}.log-warn{color:var(--yellow)}.log-err{color:var(--red)}.log-skip{color:var(--text-muted)}.log-file{color:var(--text-secondary)}.log-size{color:var(--cyan);font-size:11px}
.sys-info{display:flex;flex-wrap:wrap;gap:20px;font-size:12px;color:var(--text-muted);font-family:'JetBrains Mono',monospace;padding-top:24px;border-top:1px solid var(--border);margin-top:24px}
.sys-tag{display:flex;align-items:center;gap:6px}.sys-dot{width:6px;height:6px;border-radius:50%}.sys-dot.ok{background:var(--green);box-shadow:0 0 6px var(--green)}.sys-dot.err{background:var(--red);box-shadow:0 0 6px var(--red)}
.select-all-row{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.cb-custom{width:16px;height:16px;accent-color:var(--accent);cursor:pointer}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.summary-item{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:18px;text-align:center}
.summary-big{font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700}
.drop-zone{border:2px dashed var(--border);border-radius:var(--radius);padding:48px 24px;text-align:center;cursor:pointer;transition:all .3s;position:relative}
.drop-zone:hover,.drop-zone.dragover{border-color:var(--accent);background:var(--accent-glow)}
.drop-zone.dragover{transform:scale(1.01)}
.drop-zone-icon{font-size:40px;margin-bottom:12px;opacity:.7}
.drop-zone-text{font-size:14px;color:var(--text-secondary)}.drop-zone-hint{font-size:12px;color:var(--text-muted);margin-top:6px}
.drop-zone input[type=file]{display:none}
.upload-list{margin-top:16px}.upload-item{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text-secondary)}
.lightbox-overlay{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.88);backdrop-filter:blur(12px);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:16px;opacity:0;visibility:hidden;transition:all .3s;cursor:zoom-out}
.lightbox-overlay.open{opacity:1;visibility:visible}
.lightbox-overlay img{max-width:90vw;max-height:80vh;border-radius:var(--radius);box-shadow:0 8px 48px rgba(0,0,0,.5);object-fit:contain}
.lightbox-overlay.open img{animation:lbZoomIn .3s ease forwards}
@keyframes lbZoomIn{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}
.lightbox-name{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--text-secondary);background:var(--bg-card);padding:6px 16px;border-radius:6px;border:1px solid var(--border)}
.lightbox-close{position:absolute;top:20px;right:24px;width:40px;height:40px;border:1px solid var(--border);border-radius:50%;background:var(--bg-card);color:var(--text-primary);font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all var(--transition)}
.lightbox-close:hover{background:var(--red-glow);border-color:var(--red);color:var(--red)}
.preview-overlay{position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.92);backdrop-filter:blur(16px);display:flex;flex-direction:column;opacity:0;visibility:hidden;transition:all .3s;overflow-y:auto}
.preview-overlay.open{opacity:1;visibility:visible}
.preview-header{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid var(--border);background:var(--bg-secondary);position:sticky;top:0;z-index:2;flex-wrap:wrap;gap:12px}
.preview-header-title{font-size:16px;font-weight:600;display:flex;align-items:center;gap:10px}
.preview-header-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.preview-body{flex:1;padding:24px;max-width:1400px;margin:0 auto;width:100%}
.preview-single{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;animation:fadeIn .3s ease forwards}
.preview-single-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:13px}
.preview-single-name{color:var(--text-primary);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.preview-card-savings{font-weight:700;padding:2px 10px;border-radius:4px;font-size:11px}
.savings-positive{background:var(--green-glow);color:var(--green)}.savings-negative{background:var(--red-glow);color:var(--red)}
.compare-container{position:relative;width:100%;height:70vh;overflow:hidden;cursor:col-resize;background:var(--bg-input)}
.compare-inner{position:absolute;inset:0;transform-origin:50% 50%;transition:none;will-change:transform}
.compare-container img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain;pointer-events:none;user-select:none}
.compare-before{z-index:1;clip-path:inset(0 50% 0 0)}.compare-after{z-index:0}
.compare-slider{position:absolute;top:0;bottom:0;left:50%;width:3px;background:var(--accent-bright);z-index:3;transform:translateX(-50%);pointer-events:none}
.compare-slider::before{content:'◀ ▶';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--accent);color:#fff;font-size:10px;letter-spacing:2px;padding:6px 8px;border-radius:20px;white-space:nowrap;box-shadow:0 2px 12px rgba(0,0,0,.5)}
.compare-label{position:absolute;top:8px;z-index:4;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;padding:3px 10px;border-radius:4px}
.compare-label-before{left:8px;background:rgba(255,212,59,.2);color:var(--yellow)}.compare-label-after{right:8px;background:rgba(0,214,143,.2);color:var(--green)}
.zoom-indicator{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);z-index:5;display:flex;align-items:center;gap:8px;background:rgba(10,10,15,.85);border:1px solid var(--border);border-radius:20px;padding:5px 14px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text-secondary);backdrop-filter:blur(8px);transition:opacity .3s;pointer-events:auto}
.zoom-indicator.faded{opacity:.3}
.zoom-indicator:hover{opacity:1!important}
.zoom-btn{background:none;border:1px solid var(--border);color:var(--text-secondary);width:24px;height:24px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;transition:all var(--transition);padding:0;line-height:1}
.zoom-btn:hover{border-color:var(--accent);color:var(--accent-bright)}
.zoom-reset{background:none;border:none;color:var(--text-muted);cursor:pointer;font-family:'JetBrains Mono',monospace;font-size:11px;padding:2px 6px;border-radius:4px}
.zoom-reset:hover{color:var(--accent);background:var(--accent-glow)}
.preview-single-footer{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-top:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text-muted)}
.preview-nav{display:flex;align-items:center;gap:12px;margin-top:16px;justify-content:center}
.preview-nav-btn{width:48px;height:48px;border-radius:50%;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all var(--transition)}
.preview-nav-btn:hover{border-color:var(--accent);background:var(--accent-glow);color:var(--accent-bright)}
.preview-nav-btn:disabled{opacity:.3;cursor:not-allowed;border-color:var(--border)}
.preview-nav-btn:disabled:hover{background:var(--bg-card);color:var(--text-primary)}
.preview-counter{font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--text-secondary);min-width:80px;text-align:center}
.preview-thumbstrip{display:flex;gap:6px;margin-top:16px;overflow-x:auto;padding:4px 0;justify-content:center;flex-wrap:wrap}
.preview-thumb-item{width:48px;height:48px;border-radius:6px;border:2px solid var(--border);overflow:hidden;cursor:pointer;transition:all var(--transition);flex-shrink:0;background:var(--bg-input)}
.preview-thumb-item:hover{border-color:var(--text-muted)}
.preview-thumb-item.active{border-color:var(--accent);box-shadow:0 0 12px var(--accent-glow)}
.preview-thumb-item img{width:100%;height:100%;object-fit:cover}
.preview-loading{display:flex;align-items:center;justify-content:center;padding:60px;color:var(--text-muted);font-size:14px;gap:12px}
.spinner{width:20px;height:20px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.modal-overlay{position:fixed;inset:0;z-index:9997;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:all .3s}
.modal-overlay.open{opacity:1;visibility:visible}
.modal-box{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);width:90%;max-width:700px;max-height:80vh;overflow-y:auto;padding:28px}
.modal-title{font-size:16px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between}
.modal-close{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:20px;padding:4px}.modal-close:hover{color:var(--text-primary)}
.code-block{background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.6;color:var(--green);overflow-x:auto;white-space:pre;margin-bottom:12px;position:relative}
.code-block .copy-btn{position:absolute;top:8px;right:8px;background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary);border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer;font-family:'Outfit',sans-serif}
.code-block .copy-btn:hover{border-color:var(--accent);color:var(--accent)}
.tab-row{display:flex;gap:0;margin-bottom:16px;border-bottom:1px solid var(--border)}
.tab-btn{padding:10px 20px;border:none;background:none;color:var(--text-muted);font-family:'Outfit',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;transition:all var(--transition)}
.tab-btn.active{color:var(--accent-bright);border-bottom-color:var(--accent)}.tab-btn:hover{color:var(--text-primary)}
.lqip-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-top:16px}
.lqip-item{background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px;display:flex;gap:12px;align-items:center}
.lqip-thumb{width:40px;height:40px;border-radius:4px;object-fit:cover;image-rendering:pixelated}
.lqip-code{flex:1;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
.lqip-code:hover{color:var(--accent)}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.hidden{display:none!important}
::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}::-webkit-scrollbar-thumb:hover{background:var(--text-muted)}
@media(max-width:768px){.app-wrapper{padding:20px 12px}.card{padding:20px 16px}.form-row{grid-template-columns:1fr}.stats-bar{grid-template-columns:repeat(2,1fr)}.fpath,.td-thumb{display:none}.compare-container{height:50vh}.preview-header{flex-direction:column}.preview-thumbstrip{justify-content:flex-start}}
</style>
</head>
<body>
<div class="app-wrapper">
<header class="app-header">
    <div class="logo-mark"><div class="logo-icon">⚡</div><span class="logo-text">WebP Converter</span></div>
    <div class="logo-sub">Пакетная конвертация · PHP 8.3 · GD · v2.0</div>
</header>

<!-- UPLOAD -->
<div class="card">
    <div class="card-title"><span class="icon icon-upload">📤</span>Загрузка файлов (drag & drop)</div>
    <div class="drop-zone" id="dropZone" onclick="$('#fileInput').click()">
        <div class="drop-zone-icon">📁</div>
        <div class="drop-zone-text">Перетащите изображения сюда или кликните для выбора</div>
        <div class="drop-zone-hint">JPG, PNG, GIF · Несколько файлов</div>
        <input type="file" id="fileInput" multiple accept="image/jpeg,image/png,image/gif">
    </div>
    <div class="upload-list hidden" id="uploadList"></div>
    <div class="btn-row hidden" id="uploadActions"><button class="btn btn-primary" onclick="convertUploaded()">🚀 Конвертировать загруженные</button></div>
</div>

<!-- SETTINGS -->
<div class="card">
    <div class="card-title"><span class="icon icon-folder">📂</span>Директория и настройки</div>
    <div class="form-row"><div class="form-group" style="grid-column:1/-1"><label>Путь к папке</label><input type="text" id="inputDir" value="<?=htmlspecialchars(getcwd())?>" placeholder="/var/www/site/images"></div></div>
    <div class="form-row">
        <div class="form-group"><label>Качество WebP (1–100)</label><input type="number" id="quality" value="80" min="1" max="100"></div>
        <div class="form-group"><label>Режим сохранения</label><select id="saveMode"><option value="separate">В отдельную папку</option><option value="replace">Заменить оригиналы</option></select></div>
        <div class="form-group"><label>Макс. ширина (px, 0=нет)</label><input type="number" id="maxWidth" value="0" min="0"></div>
        <div class="form-group"><label>Макс. размер файла (MB)</label><input type="number" id="maxFilesize" value="50" min="1"></div>
    </div>
    <div class="form-row"><div class="form-group" id="outputDirGroup"><label>Папка результата</label><input type="text" id="outputDir" value="" placeholder="auto: source/webp_output"></div></div>
    <div class="toggle-row">
        <label class="toggle-label"><input type="checkbox" id="skipExisting" checked><span class="toggle-switch"></span>Пропускать существующие</label>
        <label class="toggle-label"><input type="checkbox" id="stripMeta" checked><span class="toggle-switch"></span>Удалить метаданные</label>
        <label class="toggle-label"><input type="checkbox" id="generateLQIP"><span class="toggle-switch"></span>Генерировать LQIP</label>
    </div>
    <div class="btn-row">
        <button class="btn btn-primary" id="btnScan" onclick="scanDir()">🔍 Сканировать</button>
        <button class="btn btn-secondary" onclick="checkSystem()">🩺 Система</button>
        <button class="btn btn-secondary" onclick="openConfigModal()">⚙️ Генератор конфига</button>
    </div>
</div>

<!-- SYSTEM -->
<div class="card hidden" id="sysCard"><div class="card-title"><span class="icon icon-settings">🩺</span>Системная информация</div><div class="sys-info" id="sysInfo"></div></div>

<!-- SCAN RESULTS -->
<div class="card hidden" id="scanCard">
    <div class="card-title"><span class="icon icon-files">📊</span>Результат сканирования</div>
    <div class="stats-bar" id="scanStats"></div>
    <div class="filter-bar">
        <span class="filter-label">Фильтры:</span>
        <select id="filterFormat" onchange="applyFilters()"><option value="all">Все форматы</option><option value="image/jpeg">JPEG</option><option value="image/png">PNG</option><option value="image/gif">GIF</option><option value="image/webp">WebP</option></select>
        <select id="filterStatus" onchange="applyFilters()"><option value="all">Все статусы</option><option value="convertable">Готов</option><option value="webp">Уже WebP</option><option value="too_large">Большие</option><option value="rotated">С EXIF-поворотом</option></select>
        <select id="filterSize" onchange="applyFilters()"><option value="all">Любой размер</option><option value="small">&lt; 100 KB</option><option value="medium">100 KB – 1 MB</option><option value="large">1 – 10 MB</option><option value="huge">&gt; 10 MB</option></select>
        <select id="filterGroup" onchange="applyFilters()"><option value="none">Без группировки</option><option value="folder" selected>По папкам</option></select>
        <span class="filter-count" id="filterCount"></span>
    </div>
    <div class="select-all-row"><input type="checkbox" class="cb-custom" id="selectAll" checked onchange="toggleAll(this)"><label for="selectAll" style="font-size:13px;color:var(--text-secondary);cursor:pointer">Выбрать все</label><span style="margin-left:auto;font-size:12px;color:var(--text-muted)" id="selectedCount"></span></div>
    <div class="files-table-wrap"><table class="files-table"><thead><tr><th style="width:30px"></th><th style="width:60px">Превью</th><th>Файл</th><th>Размер</th><th>Формат</th><th>Размеры</th><th>Статус</th></tr></thead><tbody id="filesBody"></tbody></table></div>
    <div class="btn-row" style="margin-top:20px">
        <button class="btn btn-primary" id="btnConvert" onclick="startConvert()">🚀 Конвертировать</button>
        <button class="btn btn-accent" onclick="startPreview()">👁 Предпросмотр</button>
        <button class="btn btn-secondary" onclick="scanDir()">🔄 Пересканировать</button>
    </div>
</div>

<!-- PROGRESS -->
<div class="card hidden" id="progressCard"><div class="card-title"><span class="icon icon-results">⚙️</span>Конвертация</div><div class="progress-wrap"><div class="progress-bar-track"><div class="progress-bar-fill" id="progressFill"></div></div><div class="progress-info"><span id="progressText">Подготовка...</span><span id="progressPct">0%</span></div></div></div>

<!-- RESULTS -->
<div class="card hidden" id="resultsCard">
    <div class="card-title"><span class="icon icon-results">✅</span>Результаты</div>
    <div class="summary-grid" id="summaryGrid"></div>
    <div class="btn-row" style="margin-bottom:16px">
        <button class="btn btn-secondary" onclick="exportCSV()">📥 CSV отчёт</button>
        <button class="btn btn-secondary" onclick="showPictureTags()">🏷 &lt;picture&gt; теги</button>
        <button class="btn btn-secondary hidden" id="btnShowLQIP" onclick="$('#lqipPanel').classList.toggle('hidden')">🖼 LQIP</button>
    </div>
    <div class="result-log" id="resultLog"></div>
    <div class="hidden" id="lqipPanel"><h4 style="margin:20px 0 8px;font-size:14px;color:var(--text-secondary)">LQIP Placeholder'ы <span style="font-weight:300;font-size:12px">(клик = копировать)</span></h4><div class="lqip-grid" id="lqipGrid"></div></div>
</div>
</div>

<!-- Preview overlay -->
<div class="preview-overlay" id="previewOverlay">
    <div class="preview-header">
        <div class="preview-header-title">👁 Предпросмотр <span style="font-size:12px;font-weight:400;color:var(--text-muted)" id="previewQualityLabel"></span> <span style="font-size:13px;font-weight:500;color:var(--text-secondary);margin-left:12px" id="previewNavLabel"></span></div>
        <div class="preview-header-actions">
            <label style="font-size:13px;color:var(--text-secondary);display:flex;align-items:center;gap:8px">Q: <input type="range" id="previewQualitySlider" min="1" max="100" value="80" style="width:120px;accent-color:var(--accent)"><span id="previewQualityValue" style="font-family:'JetBrains Mono',monospace;min-width:28px">80</span></label>
            <button class="btn btn-secondary" onclick="rerunPreview()" style="padding:8px 16px;font-size:12px">🔄 Пересчитать</button>
            <button class="btn btn-primary" onclick="closePreviewAndConvert()" style="padding:8px 16px;font-size:13px">🚀 Конвертировать</button>
            <button class="btn btn-secondary" onclick="closePreview()" style="padding:8px 16px;font-size:13px">✕</button>
        </div>
    </div>
    <div class="preview-body"><div class="stats-bar" id="previewSummary"></div><div id="previewContent"><div class="preview-loading"><div class="spinner"></div>Загрузка...</div></div></div>
</div>

<!-- Lightbox -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox(event)"><button class="lightbox-close" onclick="closeLightbox(event)">✕</button><img id="lightboxImg" src="" alt=""><div class="lightbox-name" id="lightboxName"></div></div>

<!-- Config modal -->
<div class="modal-overlay" id="configModal">
    <div class="modal-box">
        <div class="modal-title">⚙️ Генератор конфига WebP <button class="modal-close" onclick="closeModal('configModal')">✕</button></div>
        <div class="form-group" style="margin-bottom:12px"><label>Базовый путь</label><input type="text" id="configBasePath" value="/images" placeholder="/images"></div>
        <div class="tab-row"><button class="tab-btn active" onclick="loadConfig('nginx',this)">Nginx</button><button class="tab-btn" onclick="loadConfig('apache',this)">Apache</button></div>
        <div class="code-block" id="configOutput"><button class="copy-btn" onclick="copyCode('configOutput')">Копировать</button>Загрузка...</div>
    </div>
</div>

<!-- Picture tags modal -->
<div class="modal-overlay" id="pictureModal">
    <div class="modal-box">
        <div class="modal-title">🏷 HTML &lt;picture&gt; теги <button class="modal-close" onclick="closeModal('pictureModal')">✕</button></div>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">Готовые теги с fallback. Путь относительный — подставь свой base URL.</p>
        <div id="pictureTagsOutput"></div>
    </div>
</div>

<script>
const $=s=>document.querySelector(s),$$=s=>document.querySelectorAll(s);
let scannedFiles=[],lastResults=[],previewFiles=[],uploadedDir='';
async function apiPost(a,d={}){const fd=new FormData();fd.append('action',a);for(const[k,v]of Object.entries(d))fd.append(k,v);return(await fetch(location.href,{method:'POST',body:fd})).json()}
function formatB(b){if(!b)return'0 B';const u=['B','KB','MB','GB'],i=Math.floor(Math.log(b)/Math.log(1024));return(b/Math.pow(1024,i)).toFixed(1)+' '+u[i]}

$('#saveMode').addEventListener('change',()=>{$('#outputDirGroup').style.display=$('#saveMode').value==='separate'?'':'none'});

// ── Drag & Drop ──
const dz=$('#dropZone'),fi=$('#fileInput');
['dragenter','dragover'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.add('dragover')}));
['dragleave','drop'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.remove('dragover')}));
dz.addEventListener('drop',ev=>{fi.files=ev.dataTransfer.files;handleUpload()});
fi.addEventListener('change',handleUpload);
async function handleUpload(){
    const files=fi.files;if(!files.length)return;
    const fd=new FormData();fd.append('action','upload');
    for(let i=0;i<files.length;i++)fd.append('images[]',files[i]);
    const r=await(await fetch(location.href,{method:'POST',body:fd})).json();
    if(r.error){alert(r.error);return}
    uploadedDir=r.upload_dir;
    let h=r.uploaded.map(f=>`<div class="upload-item"><span>${f.name}</span><span>${f.size_human}</span></div>`).join('');
    if(r.errors.length)h+=r.errors.map(e=>`<div class="upload-item" style="color:var(--red)">${e}</div>`).join('');
    $('#uploadList').innerHTML=h;$('#uploadList').classList.remove('hidden');$('#uploadActions').classList.remove('hidden');
}
async function convertUploaded(){if(!uploadedDir)return;$('#inputDir').value=uploadedDir;await scanDir();startConvert()}

// ── System ──
async function checkSystem(){
    const d=await apiPost('check');$('#sysCard').classList.remove('hidden');
    $('#sysInfo').innerHTML=[{l:'PHP',v:d.php_version,ok:1},{l:'GD',v:d.gd_loaded?'Да':'НЕТ',ok:d.gd_loaded},{l:'WebP',v:d.webp_support?'Да':'НЕТ',ok:d.webp_support},{l:'EXIF',v:d.exif_support?'Да':'НЕТ',ok:d.exif_support},{l:'Memory',v:d.memory_limit,ok:1}].map(i=>`<div class="sys-tag"><span class="sys-dot ${i.ok?'ok':'err'}"></span><strong>${i.l}:</strong> ${i.v}</div>`).join('');
}

// ── Scan ──
async function scanDir(){
    const btn=$('#btnScan');btn.disabled=true;btn.innerHTML='⏳ Сканирую...';
    try{
        const d=await apiPost('scan',{directory:$('#inputDir').value,max_filesize:$('#maxFilesize').value});
        if(d.error){alert(d.error);return}
        scannedFiles=d.files;const s=d.stats;
        $('#scanStats').innerHTML=`<div class="stat-item"><div class="stat-value" style="color:var(--accent-bright)">${s.total}</div><div class="stat-label">Всего</div></div><div class="stat-item"><div class="stat-value" style="color:var(--green)">${s.convertable}</div><div class="stat-label">К конвертации</div></div><div class="stat-item"><div class="stat-value" style="color:var(--cyan)">${s.already_webp}</div><div class="stat-label">WebP</div></div><div class="stat-item"><div class="stat-value" style="color:var(--yellow)">${s.too_large}</div><div class="stat-label">Большие</div></div><div class="stat-item"><div class="stat-value">${s.total_size}</div><div class="stat-label">Размер</div></div>`;
        applyFilters();$('#scanCard').classList.remove('hidden');$('#resultsCard').classList.add('hidden');$('#progressCard').classList.add('hidden');
    }catch(e){alert(e.message)}
    btn.disabled=false;btn.innerHTML='🔍 Сканировать';
}

// ── Filters ──
function applyFilters(){
    const fmt=$('#filterFormat').value,st=$('#filterStatus').value,sz=$('#filterSize').value,gr=$('#filterGroup').value;
    let f=scannedFiles.filter(f=>{
        if(fmt!=='all'&&f.mime!==fmt)return false;
        if(st==='convertable'&&!f.convertable)return false;
        if(st==='webp'&&!f.is_webp)return false;
        if(st==='too_large'&&!f.too_large)return false;
        if(st==='rotated'&&f.exif_orient<=1)return false;
        if(sz==='small'&&f.size>=102400)return false;
        if(sz==='medium'&&(f.size<102400||f.size>=1048576))return false;
        if(sz==='large'&&(f.size<1048576||f.size>=10485760))return false;
        if(sz==='huge'&&f.size<10485760)return false;
        return true;
    });
    $('#filterCount').textContent=`${f.length} из ${scannedFiles.length}`;
    renderTable(f,gr==='folder');updateSelectedCount();
}
function renderTable(files,grouped){
    const mi={'image/jpeg':'🖼','image/png':'🎨','image/gif':'🎞','image/webp':'🌐'};let h='';
    if(grouped){
        const g={};files.forEach(f=>{const k=f.folder||'/ (корень)';if(!g[k])g[k]=[];g[k].push(f)});
        for(const[folder,items]of Object.entries(g)){
            h+=`<tr class="folder-group-header" onclick="toggleFolder(this)"><td colspan="7"><span class="arrow">▼</span> 📁 ${folder} <span class="folder-count">${items.length}</span></td></tr>`;
            items.forEach(f=>h+=fileRow(f,scannedFiles.indexOf(f),mi));
        }
    } else files.forEach(f=>h+=fileRow(f,scannedFiles.indexOf(f),mi));
    $('#filesBody').innerHTML=h;
}
function fileRow(f,i,mi){
    let b=f.is_webp?'<span class="badge badge-webp">WebP</span>':f.too_large?'<span class="badge badge-err">Большой</span>':f.convertable?'<span class="badge badge-ok">Готов</span>':'<span class="badge badge-skip">—</span>';
    const rot=(f.exif_orient>1)?' <span class="badge badge-skip">🔄</span>':'';
    const tu='?thumb='+encodeURIComponent(f.path)+'&size=80',pu='?preview='+encodeURIComponent(f.path);
    return`<tr data-idx="${i}"><td><input type="checkbox" class="cb-custom file-cb" data-idx="${i}" ${f.convertable?'checked':''} ${f.convertable?'':'disabled'} onchange="updateSelectedCount()"></td><td class="td-thumb"><img class="thumb-img" src="${tu}" alt="" loading="lazy" onclick="openLightbox('${pu.replace(/'/g,"\\'")}','${f.name.replace(/'/g,"\\'")}')" title="Полный размер"></td><td><div class="fname">${f.name}</div><div class="fpath">${f.relative}</div></td><td>${f.size_human}</td><td>${mi[f.mime]||'📄'} ${f.mime.split('/')[1]}</td><td>${f.dimensions}</td><td>${b}${rot}</td></tr>`;
}
function toggleFolder(h){h.classList.toggle('collapsed');let n=h.nextElementSibling;while(n&&!n.classList.contains('folder-group-header')){n.style.display=h.classList.contains('collapsed')?'none':'';n=n.nextElementSibling}}
function toggleAll(m){$$('.file-cb:not(:disabled)').forEach(c=>c.checked=m.checked);updateSelectedCount()}
function updateSelectedCount(){const c=$$('.file-cb:checked:not(:disabled)').length,t=$$('.file-cb:not(:disabled)').length;$('#selectedCount').textContent=`${c} из ${t}`;$('#btnConvert').disabled=c===0}

// ── Convert ──
async function startConvert(){
    if($('#saveMode').value==='replace'&&!confirm('⚠️ Оригиналы будут ЗАМЕНЕНЫ. Продолжить?'))return;
    const sel=[];$$('.file-cb:checked:not(:disabled)').forEach(c=>sel.push(+c.dataset.idx));
    $('#progressCard').classList.remove('hidden');$('#resultsCard').classList.add('hidden');$('#btnConvert').disabled=true;
    $('#progressFill').style.width='0%';$('#progressText').textContent='Конвертация...';$('#progressPct').textContent='0%';
    let fk=0;const iv=setInterval(()=>{fk=Math.min(fk+Math.random()*8,90);$('#progressFill').style.width=fk+'%';$('#progressPct').textContent=Math.round(fk)+'%'},200);
    try{
        const d=await apiPost('convert',{directory:$('#inputDir').value,quality:$('#quality').value,mode:$('#saveMode').value,
            output_dir:$('#outputDir').value,skip_existing:$('#skipExisting').checked?'1':'0',strip_metadata:$('#stripMeta').checked?'1':'0',
            max_width:$('#maxWidth').value||'0',max_filesize:$('#maxFilesize').value,generate_lqip:$('#generateLQIP').checked?'1':'0',
            files:JSON.stringify(sel.map(i=>scannedFiles[i].relative))});
        clearInterval(iv);if(d.error){alert(d.error);$('#progressCard').classList.add('hidden');$('#btnConvert').disabled=false;return}
        lastResults=d.results;$('#progressFill').style.width='100%';$('#progressPct').textContent='100%';$('#progressText').textContent='Готово!';
        const sm=d.summary;
        $('#summaryGrid').innerHTML=`<div class="summary-item"><div class="summary-big" style="color:var(--green)">${sm.converted}</div><div class="stat-label">Конвертировано</div></div><div class="summary-item"><div class="summary-big" style="color:var(--yellow)">${sm.skipped}</div><div class="stat-label">Пропущено</div></div><div class="summary-item"><div class="summary-big" style="color:var(--red)">${sm.errors}</div><div class="stat-label">Ошибок</div></div><div class="summary-item"><div class="summary-big">${sm.size_before}</div><div class="stat-label">Было</div></div><div class="summary-item"><div class="summary-big" style="color:var(--cyan)">${sm.size_after}</div><div class="stat-label">Стало</div></div><div class="summary-item"><div class="summary-big" style="color:var(--green)">${sm.saved}</div><div class="stat-label">Экономия (${sm.saved_pct}%)</div></div>`;
        const hasLQIP=d.results.some(r=>r.lqip);$('#btnShowLQIP').classList.toggle('hidden',!hasLQIP);
        if(hasLQIP){$('#lqipGrid').innerHTML=d.results.filter(r=>r.lqip).map(r=>`<div class="lqip-item"><img class="lqip-thumb" src="${r.lqip}" alt=""><div class="lqip-code" onclick="navigator.clipboard.writeText(this.dataset.v);this.style.color='var(--green)';setTimeout(()=>this.style.color='',1000)" data-v="${r.lqip.replace(/"/g,'&quot;')}" title="Скопировать base64">${r.file} · ${r.lqip.length} chars</div></div>`).join('')}
        $('#resultLog').innerHTML=d.results.map(r=>{
            if(r.skipped)return`<div class="log-line"><span class="log-skip">SKIP</span><span class="log-file">${r.file}</span><span class="log-skip">— ${r.reason}</span></div>`;
            if(r.error)return`<div class="log-line"><span class="log-err">FAIL</span><span class="log-file">${r.file}</span><span class="log-err">— ${r.error}</span></div>`;
            const c=r.saved_pct>0?'log-ok':'log-warn',s=r.saved_pct>0?'−':'+';
            return`<div class="log-line"><span class="${c}">✓</span><span class="log-file">${r.file}</span><span class="log-size">${formatB(r.size_before)}→${formatB(r.size_after)}</span><span class="${c}">${s}${Math.abs(r.saved_pct)}%</span>${r.warning?'<span class="log-warn">⚠'+r.warning+'</span>':''}</div>`;
        }).join('')||'<span style="color:var(--text-muted)">Пусто</span>';
        $('#resultsCard').classList.remove('hidden');
    }catch(e){clearInterval(iv);alert(e.message)}$('#btnConvert').disabled=false;
}

// ── CSV ──
function exportCSV(){const rows=lastResults.map(r=>[r.file||'',r.skipped?'Skip':r.error?'Error':'OK',r.size_before||'',r.size_after||'',r.saved_pct||'',r.error||'']);window.open('?export_csv='+btoa(unescape(encodeURIComponent(JSON.stringify(rows)))),'_blank')}

// ── Picture tags ──
function showPictureTags(){
    const tags=lastResults.filter(r=>r.success&&r.file).map(r=>{const w=r.file.replace(/\.(jpe?g|png|gif)$/i,'.webp');
        return`<div class="code-block"><button class="copy-btn" onclick="copyInner(this)">Copy</button>&lt;picture&gt;\n  &lt;source srcset="${w}" type="image/webp"&gt;\n  &lt;img src="${r.file}" alt="" loading="lazy"&gt;\n&lt;/picture&gt;</div>`}).join('');
    $('#pictureTagsOutput').innerHTML=tags||'<p style="color:var(--text-muted)">Нет данных</p>';$('#pictureModal').classList.add('open');
}

// ── Config ──
function openConfigModal(){$('#configModal').classList.add('open');loadConfig('nginx',$('.tab-btn'))}
async function loadConfig(t,btn){$$('.tab-btn').forEach(b=>b.classList.remove('active'));btn.classList.add('active');
    const d=await apiPost('generate_config',{type:t,base_url:$('#configBasePath').value});
    if(d.config)$('#configOutput').innerHTML=`<button class="copy-btn" onclick="copyCode('configOutput')">Копировать</button>`+d.config.replace(/</g,'&lt;').replace(/>/g,'&gt;')}
$('#configBasePath').addEventListener('input',()=>{const a=$('.tab-btn.active');if(a)loadConfig(a.textContent.includes('Nginx')?'nginx':'apache',a)});

// ── Preview ──
$('#previewQualitySlider').addEventListener('input',e=>{$('#previewQualityValue').textContent=e.target.value});
let trialData=[],trialIdx=0;
async function startPreview(){const sel=[];$$('.file-cb:checked:not(:disabled)').forEach(c=>sel.push(+c.dataset.idx));if(!sel.length){alert('Выберите файлы');return}
    previewFiles=sel.map(i=>scannedFiles[i].path);const q=$('#quality').value;$('#previewQualitySlider').value=q;$('#previewQualityValue').textContent=q;
    $('#previewOverlay').classList.add('open');document.body.style.overflow='hidden';await runTrial(previewFiles,+q)}
async function rerunPreview(){const q=+$('#previewQualitySlider').value;$('#quality').value=q;await runTrial(previewFiles,q)}
async function runTrial(paths,q){
    $('#previewContent').innerHTML=`<div class="preview-loading"><div class="spinner"></div>Q:${q} · ${paths.length} файлов...</div>`;$('#previewSummary').innerHTML='';$('#previewQualityLabel').textContent=` · Q:${q}`;$('#previewNavLabel').textContent='';
    try{const d=await apiPost('trial_batch',{files:JSON.stringify(paths),quality:''+q,max_width:''+(+$('#maxWidth').value||0)});
        if(d.error){$('#previewContent').innerHTML=`<div style="color:var(--red);padding:40px;text-align:center">${d.error}</div>`;return}
        const sm=d.summary,sc=sm.saved_pct>0?'var(--green)':'var(--red)';
        $('#previewSummary').innerHTML=`<div class="stat-item"><div class="stat-value" style="color:var(--accent-bright)">${sm.total}</div><div class="stat-label">Файлов</div></div><div class="stat-item"><div class="stat-value">${formatB(sm.size_before)}</div><div class="stat-label">Было</div></div><div class="stat-item"><div class="stat-value" style="color:var(--cyan)">${formatB(sm.size_after)}</div><div class="stat-label">Стало</div></div><div class="stat-item"><div class="stat-value" style="color:${sc}">${sm.saved_pct}%</div><div class="stat-label">Экономия</div></div>`;
        trialData=d.results.map(r=>({...r,q}));trialIdx=0;
        // Render viewer + thumb strip
        let thumbs=trialData.map((r,i)=>{
            const src=r.success?'?thumb='+encodeURIComponent(r.original_path)+'&size=80':'';
            return`<div class="preview-thumb-item ${i===0?'active':''}" onclick="goTrial(${i})" data-ti="${i}">${src?`<img src="${src}" alt="">`:''}</div>`;
        }).join('');
        $('#previewContent').innerHTML=`<div id="previewViewer"></div><div class="preview-nav"><button class="preview-nav-btn" id="prevBtn" onclick="goTrial(trialIdx-1)">◀</button><span class="preview-counter" id="previewCounter"></span><button class="preview-nav-btn" id="nextBtn" onclick="goTrial(trialIdx+1)">▶</button></div><div class="preview-thumbstrip" id="previewThumbs">${thumbs}</div>`;
        renderTrialItem(0);
    }catch(e){$('#previewContent').innerHTML=`<div style="color:var(--red);padding:40px;text-align:center">${e.message}</div>`}
}
function goTrial(i){if(i<0||i>=trialData.length)return;trialIdx=i;renderTrialItem(i)}
function renderTrialItem(i){
    const r=trialData[i],q=r.q;
    $('#previewNavLabel').textContent=`${i+1} / ${trialData.length}`;
    $('#previewCounter').textContent=`${i+1} / ${trialData.length}`;
    $('#prevBtn').disabled=i===0;$('#nextBtn').disabled=i===trialData.length-1;
    // Update thumb strip active
    $$('.preview-thumb-item').forEach((t,ti)=>t.classList.toggle('active',ti===i));
    // Scroll active thumb into view
    const activeThumb=$$(`.preview-thumb-item`)[i];if(activeThumb)activeThumb.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
    if(!r.success){$('#previewViewer').innerHTML=`<div class="preview-single"><div class="preview-single-header"><span class="preview-single-name">${r.name||'?'}</span><span class="preview-card-savings savings-negative">ОШИБКА</span></div><div style="padding:48px;color:var(--red);text-align:center;font-size:14px">${r.error}</div></div>`;return}
    const p=r.saved_pct,cl=p>0?'savings-positive':'savings-negative',sg=p>0?'−':'+';
    const o='?preview='+encodeURIComponent(r.original_path),t='?trial='+encodeURIComponent(r.trial_id);
    $('#previewViewer').innerHTML=`<div class="preview-single">
        <div class="preview-single-header"><span class="preview-single-name">${r.name}</span><span style="color:var(--text-muted);margin:0 auto 0 16px">${formatB(r.size_before)} → ${formatB(r.size_after)}</span><span class="preview-card-savings ${cl}">${sg}${Math.abs(p)}%</span></div>
        <div class="compare-container" id="cmpBox" onmousedown="startCmp(event)" ontouchstart="startCmp(event)">
            <div class="compare-inner" id="cmpInner">
                <span class="compare-label compare-label-before">Оригинал</span><span class="compare-label compare-label-after">WebP Q:${q}</span>
                <img class="compare-before" src="${o}"><img class="compare-after" src="${t}">
                <div class="compare-slider"></div>
            </div>
            <div class="zoom-indicator" id="zoomIndicator">
                <button class="zoom-btn" onclick="event.stopPropagation();zoomBy(-1)">−</button>
                <span id="zoomLevel">1×</span>
                <button class="zoom-btn" onclick="event.stopPropagation();zoomBy(1)">+</button>
                <button class="zoom-reset" onclick="event.stopPropagation();resetZoom()">сброс</button>
            </div>
        </div>
        <div class="preview-single-footer"><span>Колёсико — зум · Зажать и двигать — сравнение</span><span>При зуме: ПКМ / Shift+drag — панорама</span></div>
    </div>`;
    resetZoom();
}
// ── Zoom & Pan state ──
let zoomScale=1, panX=0, panY=0, isPanning=false;
const ZOOM_MIN=1,ZOOM_MAX=10,ZOOM_STEP=0.15;

function resetZoom(){zoomScale=1;panX=0;panY=0;applyZoom();const zi=$('#zoomIndicator');if(zi)zi.classList.remove('faded')}
function zoomBy(dir){
    const old=zoomScale;
    zoomScale=Math.max(ZOOM_MIN,Math.min(ZOOM_MAX, zoomScale*(dir>0?1.4:1/1.4)));
    if(zoomScale<=1.01){zoomScale=1;panX=0;panY=0}
    applyZoom();
}
function applyZoom(){
    const inner=$('#cmpInner');if(!inner)return;
    // Clamp pan so we don't go out of bounds
    if(zoomScale<=1){panX=0;panY=0}else{
        const maxPanX=(zoomScale-1)*50;const maxPanY=(zoomScale-1)*50;
        panX=Math.max(-maxPanX,Math.min(maxPanX,panX));
        panY=Math.max(-maxPanY,Math.min(maxPanY,panY));
    }
    inner.style.transform=`scale(${zoomScale}) translate(${panX/zoomScale}px, ${panY/zoomScale}px)`;
    inner.style.transformOrigin='50% 50%';
    const lvl=$('#zoomLevel');if(lvl)lvl.textContent=zoomScale<=1.01?'1×':zoomScale.toFixed(1)+'×';
    // Fade indicator when zoomed and idle
    const zi=$('#zoomIndicator');
    if(zi){zi.classList.toggle('faded',zoomScale>1.5);clearTimeout(zi._fadeTimer);zi._fadeTimer=setTimeout(()=>{if(zoomScale>1.5)zi.classList.add('faded')},2000)}
    // Change cursor
    const box=$('#cmpBox');if(box)box.style.cursor=zoomScale>1.01?'grab':'col-resize';
}

// Wheel zoom
document.addEventListener('wheel',ev=>{
    const box=$('#cmpBox');if(!box||!box.contains(ev.target))return;
    ev.preventDefault();
    const rect=box.getBoundingClientRect();
    const mx=(ev.clientX-rect.left)/rect.width;
    const my=(ev.clientY-rect.top)/rect.height;
    const oldScale=zoomScale;
    const dir=ev.deltaY<0?1:-1;
    zoomScale=Math.max(ZOOM_MIN,Math.min(ZOOM_MAX, zoomScale*(1+dir*ZOOM_STEP)));
    if(zoomScale<=1.01){zoomScale=1;panX=0;panY=0}else{
        // Adjust pan to zoom toward cursor
        const factor=zoomScale/oldScale;
        const cx=(mx-0.5)*rect.width;
        const cy=(my-0.5)*rect.height;
        panX=cx-(cx-panX)*factor;
        panY=cy-(cy-panY)*factor;
    }
    applyZoom();
},{passive:false});

// Compare slider + Pan
function startCmp(e){
    e.preventDefault();
    const c=$('#cmpBox');if(!c)return;
    const inner=$('#cmpInner');if(!inner)return;
    // Right-click or shift = pan mode (when zoomed)
    const wantPan=zoomScale>1.01&&(e.button===2||e.shiftKey||e.buttons===4);
    if(wantPan){
        c.style.cursor='grabbing';
        const startX=e.clientX||e.touches?.[0]?.clientX||0;
        const startY=e.clientY||e.touches?.[0]?.clientY||0;
        const startPanX=panX,startPanY=panY;
        const mv=ev=>{
            const cx=ev.clientX||ev.touches?.[0]?.clientX||0;
            const cy=ev.clientY||ev.touches?.[0]?.clientY||0;
            panX=startPanX+(cx-startX);panY=startPanY+(cy-startY);
            applyZoom();
        };
        const st=()=>{document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',st);document.removeEventListener('touchmove',mv);document.removeEventListener('touchend',st);c.style.cursor=zoomScale>1.01?'grab':'col-resize'};
        document.addEventListener('mousemove',mv);document.addEventListener('mouseup',st);document.addEventListener('touchmove',mv,{passive:false});document.addEventListener('touchend',st);
        return;
    }
    // Normal: comparison slider
    const mv=ev=>{const rect=c.getBoundingClientRect(),cx=ev.touches?ev.touches[0].clientX:ev.clientX,x=Math.max(0,Math.min(1,(cx-rect.left)/rect.width)),p=x*100;
        inner.querySelector('.compare-before').style.clipPath=`inset(0 ${100-p}% 0 0)`;inner.querySelector('.compare-slider').style.left=p+'%'};
    const st=()=>{document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',st);document.removeEventListener('touchmove',mv);document.removeEventListener('touchend',st)};
    document.addEventListener('mousemove',mv);document.addEventListener('mouseup',st);document.addEventListener('touchmove',mv,{passive:false});document.addEventListener('touchend',st);mv(e);
}
// Prevent context menu on compare area
document.addEventListener('contextmenu',e=>{const box=$('#cmpBox');if(box&&box.contains(e.target)&&zoomScale>1.01)e.preventDefault()});
function closePreview(){$('#previewOverlay').classList.remove('open');document.body.style.overflow='';apiPost('trial_clean').catch(()=>{})}
function closePreviewAndConvert(){closePreview();setTimeout(startConvert,300)}

// ── Lightbox ──
function openLightbox(u,n){$('#lightboxImg').src='';$('#lightboxImg').src=u;$('#lightboxName').textContent=n;$('#lightbox').classList.add('open');document.body.style.overflow='hidden'}
function closeLightbox(e){if(e&&e.target.tagName==='IMG')return;$('#lightbox').classList.remove('open');document.body.style.overflow=''}

// ── Modals ──
function closeModal(id){$('#'+id).classList.remove('open')}
$$('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
function copyCode(id){const el=$('#'+id),t=el.innerText.replace('Копировать','').trim();navigator.clipboard.writeText(t);const b=el.querySelector('.copy-btn');if(b){b.textContent='✓';setTimeout(()=>b.textContent='Копировать',1500)}}
function copyInner(b){const t=b.parentElement.innerText.replace('Copy','').trim();navigator.clipboard.writeText(t);b.textContent='✓';setTimeout(()=>b.textContent='Copy',1500)}

// ── Keyboard ──
document.addEventListener('keydown',e=>{
    if($('#previewOverlay').classList.contains('open')){
        if(e.key==='Escape')closePreview();
        else if(e.key==='ArrowLeft')goTrial(trialIdx-1);
        else if(e.key==='ArrowRight')goTrial(trialIdx+1);
        else if(e.key==='='||e.key==='+')zoomBy(1);
        else if(e.key==='-')zoomBy(-1);
        else if(e.key==='0')resetZoom();
        return;
    }
    if(e.key==='Escape'){
        if($('#lightbox').classList.contains('open'))closeLightbox(e);
        else $$('.modal-overlay.open').forEach(m=>m.classList.remove('open'));
    }});

checkSystem();
</script>
</body>
</html>
