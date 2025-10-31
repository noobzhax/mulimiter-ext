<?php
ini_set('display_errors', 1);
error_reporting(0);

session_start();

$dir = '/root/.mulimiter';
$app_name = 'mulimiter';

// Helper functions: reliable file I/O for save/disabled
function ml_read_file($path) {
    return file_exists($path) ? file_get_contents($path) : '';
}
function ml_write_file($path, $content) {
    $dirn = dirname($path);
    if (!is_dir($dirn)) @mkdir($dirn, 0755, true);
    $content = str_replace("\r\n", "\n", $content);
    $content = rtrim($content, "\n");
    file_put_contents($path, $content . (strlen($content) ? "\n" : ""));
}

// metadata helpers to persist rule info by mulid
function ml_meta_clear() { ml_write_file(ml_meta_path(), json_encode(new stdClass())); }
function ml_rate_to_int($s) {
    if (preg_match('/(\d+)/', $s, $m)) return intval($m[1]);
    return 0;
}
function ml_meta_path() { global $dir; return "$dir/meta.json"; }
function ml_meta_read() {
    $raw = ml_read_file(ml_meta_path());
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function ml_meta_write($meta) {
    $json = json_encode($meta);
    ml_write_file(ml_meta_path(), $json);
}
function ml_meta_set($mulid, $rec) {
    $meta = ml_meta_read();
    $cur = isset($meta[$mulid]) && is_array($meta[$mulid]) ? $meta[$mulid] : [];
    $meta[$mulid] = array_merge($cur, $rec);
    ml_meta_write($meta);
}
function ml_meta_remove($mulid) {
    $meta = ml_meta_read();
    if (isset($meta[$mulid])) { unset($meta[$mulid]); ml_meta_write($meta); }
}
function ml_extract_mulid($rule) {
    $parts = explode('--hashlimit-name', $rule);
    if (count($parts) > 1) {
        $name = trim(explode(' ', $parts[1])[0]);
        return str_replace(['mulimiter_d','mulimiter_u'], '', $name);
    }
    return '';
}

function ml_meta_migrate_if_needed() {
    global $dir;
    $meta = ml_meta_read();
    if (!empty($meta)) return; // already initialized
    $pairs = [];
    $activeSet = [];
    $save_text = str_replace("\r\n", "\n", ml_read_file("$dir/save"));
    $save_arr = array_filter(explode("\n", trim($save_text, "\n")));
    foreach ($save_arr as $line) {
        if (strpos($line, 'mulimiter') === FALSE) continue;
        $mid = ml_extract_mulid($line);
        if (!$mid) continue;
        if (!isset($pairs[$mid])) $pairs[$mid] = ['d'=>'', 'u'=>''];
        if (strpos($line, 'mulimiter_d') !== FALSE) $pairs[$mid]['d'] = $line; else $pairs[$mid]['u'] = $line;
        $activeSet[$mid] = true;
    }
    $dis_text = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
    $dis_arr = array_filter(explode("\n", trim($dis_text, "\n")));
    foreach ($dis_arr as $line) {
        if (strpos($line, 'mulimiter') === FALSE) continue;
        $mid = ml_extract_mulid($line);
        if (!$mid) continue;
        if (!isset($pairs[$mid])) $pairs[$mid] = ['d'=>'', 'u'=>''];
        if (strpos($line, 'mulimiter_d') !== FALSE) $pairs[$mid]['d'] = $line; else $pairs[$mid]['u'] = $line;
        if (!isset($activeSet[$mid])) $activeSet[$mid] = false;
    }
    if (empty($pairs)) return;
    foreach ($pairs as $mid => $ru) {
        $download_rule = $ru['d'];
        $upload_rule = $ru['u'];
        $iprange = '';
        $dspeed = 0; $uspeed = 0;
        $t0 = ''; $t1 = ''; $wd = '';
        if ($download_rule && preg_match('/--dst-range ([^ ]+)/', $download_rule, $m)) { $iprange = $m[1]; }
        elseif ($upload_rule && preg_match('/--src-range ([^ ]+)/', $upload_rule, $m)) { $iprange = $m[1]; }
        if ($download_rule && preg_match('/--hashlimit-above ([^ ]+)/', $download_rule, $m)) { $dspeed = ml_rate_to_int($m[1]); }
        if ($upload_rule && preg_match('/--hashlimit-above ([^ ]+)/', $upload_rule, $m)) { $uspeed = ml_rate_to_int($m[1]); }
        $src_for_time = $download_rule ?: $upload_rule;
        if ($src_for_time && preg_match('/--timestart ([^ ]+)/', $src_for_time, $m)) { $t0 = $m[1]; }
        if ($src_for_time && preg_match('/--timestop ([^ ]+)/', $src_for_time, $m)) { $t1 = $m[1]; }
        if ($src_for_time && preg_match('/--weekdays ([^ ]+)/', $src_for_time, $m)) { $wd = $m[1]; }
        if ($iprange || $dspeed || $uspeed || $t0 || $t1 || $wd) {
            ml_meta_set($mid, [
                'iprange' => $iprange,
                'dspeed' => $dspeed,
                'uspeed' => $uspeed,
                'timestart' => $t0,
                'timestop' => $t1,
                'weekdays' => $wd,
                'status' => ($activeSet[$mid] ? 'active' : 'disabled')
            ]);
        }
    }
}
function ml_meta_rebuild() {
    ml_meta_clear();
    ml_meta_migrate_if_needed();
}

// ensure metadata exists
ml_meta_migrate_if_needed();

if ($_SESSION[$app_name]['logedin'] == true) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($_GET['act'] == 'add') {
            if ($_POST['iprange0'] && $_POST['dspeed'] && $_POST['uspeed']) {
                $iprange = $_POST['iprange0'] . '-' . ($_POST['iprange1'] ? $_POST['iprange1'] : $_POST['iprange0']);
                $uspeed   = $_POST['uspeed'] . 'kb/s';
                $dspeed   = $_POST['dspeed'] . 'kb/s';

                $timestart = $_POST['timestart'];
                $timestop = $_POST['timestop'];

                $mod_time = '';
                if ($timestart && $timestop) {
                    $mod_time = "-m time --timestart $timestart --timestop $timestop";
                }

                $arr_weekdays = $_POST['weekdays'];
                $weekdays = '';
                if ($arr_weekdays) {
                    $weekdays = implode(',', $arr_weekdays);
                    if ($mod_time) {
                        $mod_time .= " --weekdays $weekdays";
                    } else {
                        $mod_time .= "-m time --weekdays $weekdays";
                    }
                }

                $mulid = dechex(time());
                $uname = 'mulimiter_u' . $mulid;
                $dname = 'mulimiter_d' . $mulid;

                //APPLY
                //download
                shell_exec("iptables -I FORWARD -m iprange --dst-range $iprange -m hashlimit --hashlimit-above $dspeed --hashlimit-mode dstip --hashlimit-name $dname $mod_time -j DROP");
                //upload
                shell_exec("iptables -I FORWARD -m iprange --src-range $iprange -m hashlimit --hashlimit-above $uspeed --hashlimit-mode srcip --hashlimit-name $uname $mod_time -j DROP");
                //END APPLY

                $list = shell_exec('iptables -S');
                $list = str_replace("\r\n", "\n", $list);
                $list = explode("\n", $list);
                $limiters = [];
                $dcurrent = '';
                $ucurrent = '';
                foreach ($list as $ls) {
                    if (strpos($ls, 'mulimiter') !== FALSE) {
                        $limiters[] = $ls;
                    }
                    if (strpos($ls, $dname) !== FALSE) {
                        $dcurrent = $ls;
                    }
                    if (strpos($ls, $uname) !== FALSE) {
                        $ucurrent = $ls;
                    }
                }
                if ($dcurrent && $ucurrent) {
                    $old    = ml_read_file("$dir/save");
                    $new    = trim($dcurrent . "\n" . $old, "\n");
                    $new    = trim($ucurrent . "\n" . $new, "\n");
                    ml_write_file("$dir/save", $new);
                    // persist metadata
                    ml_meta_set($mulid, [
                        'iprange' => $iprange,
                        'dspeed' => intval($_POST['dspeed']),
                        'uspeed' => intval($_POST['uspeed']),
                        'timestart' => $timestart,
                        'timestop' => $timestop,
                        'weekdays' => $weekdays,
                        'status' => 'active'
                    ]);
                    echo json_encode([
                        'success' => true
                    ]);
                }
            }
        } elseif ($_GET['act'] == 'delete') {
            if ($_POST['drule'] || $_POST['urule']) {
                $drule           = (isset($_POST['drule']) && $_POST['drule']) ? base64_decode($_POST['drule']) : '';
                $urule           = (isset($_POST['urule']) && $_POST['urule']) ? base64_decode($_POST['urule']) : '';

                $delete_drule    = $drule ? str_replace('-A ', '-D ', $drule) : '';
                $delete_urule    = $urule ? str_replace('-A ', '-D ', $urule) : '';

                $saved_rules    = str_replace("\r\n", "\n", ml_read_file("$dir/save"));
                $saved_rules    = explode("\n", $saved_rules);
                $untouched      = '';
                foreach ($saved_rules as $sv) {
                    if ($sv != $urule && $sv != $drule) {
                        $untouched .= $sv . "\n";
                    }
                }

                $untouched = trim($untouched, "\n");

                shell_exec("iptables $delete_drule");
                shell_exec("iptables $delete_urule");

                ml_write_file("$dir/save", $untouched);

                // also remove from disabled store if present
                if (file_exists("$dir/disabled")) {
                    $disabled_rules = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
                    $disabled_rules = explode("\n", trim($disabled_rules, "\n"));
                    $remain_disabled = '';
                    foreach ($disabled_rules as $sv) {
                        if ($sv && $sv != $urule && $sv != $drule) {
                            $remain_disabled .= $sv . "\n";
                        }
                    }
                    ml_write_file("$dir/disabled", trim($remain_disabled, "\n"));
                }

                echo json_encode([
                    'success' => true
                ]);
            }
        } elseif ($_GET['act'] == 'disable') {
            if ($_POST['drule'] || $_POST['urule']) {
                $drule           = (isset($_POST['drule']) && $_POST['drule']) ? base64_decode($_POST['drule']) : '';
                $urule           = (isset($_POST['urule']) && $_POST['urule']) ? base64_decode($_POST['urule']) : '';

                $delete_drule    = $drule ? str_replace('-A ', '-D ', $drule) : '';
                $delete_urule    = $urule ? str_replace('-A ', '-D ', $urule) : '';

                if (!file_exists("$dir/disabled")) { ml_write_file("$dir/disabled", ""); }

                $saved_rules     = str_replace("\r\n", "\n", ml_read_file("$dir/save"));
                $saved_rules     = explode("\n", trim($saved_rules, "\n"));
                $remain          = '';
                foreach ($saved_rules as $sv) {
                    if ($sv && $sv != $urule && $sv != $drule) {
                        $remain .= $sv . "\n";
                    }
                }

                // remove from active iptables
                if ($delete_drule) shell_exec("iptables $delete_drule");
                if ($delete_urule) shell_exec("iptables $delete_urule");

                // move to disabled store
                $disabled_old = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
                $to_add = '';
                if ($drule) $to_add .= $drule . "\n";
                if ($urule) $to_add .= $urule . "\n";
                $disabled_new = trim($to_add . $disabled_old, "\n");

                ml_write_file("$dir/save", $remain);
                ml_write_file("$dir/disabled", $disabled_new);

                // update metadata status
                $mulid_a = $drule ? ml_extract_mulid($drule) : ($urule ? ml_extract_mulid($urule) : '');
                if ($mulid_a) { ml_meta_set($mulid_a, ['status' => 'disabled']); }

                echo json_encode([
                    'success' => true
                ]);
            }
        } elseif ($_GET['act'] == 'enable') {
            if ($_POST['drule'] || $_POST['urule']) {
                $drule           = (isset($_POST['drule']) && $_POST['drule']) ? base64_decode($_POST['drule']) : '';
                $urule           = (isset($_POST['urule']) && $_POST['urule']) ? base64_decode($_POST['urule']) : '';

                $insert_drule    = $drule ? str_replace('-A ', '-I ', $drule) : '';
                $insert_urule    = $urule ? str_replace('-A ', '-I ', $urule) : '';

                // reconstruct missing side from metadata if available (single-click full restore)
                $mulid_meta = $drule ? ml_extract_mulid($drule) : ($urule ? ml_extract_mulid($urule) : '');
                if ($mulid_meta) {
                    $meta_all = ml_meta_read();
                    if (isset($meta_all[$mulid_meta])) {
                        $rec = $meta_all[$mulid_meta];
                        $iprange = isset($rec['iprange']) ? $rec['iprange'] : '';
                        $ds = isset($rec['dspeed']) ? intval($rec['dspeed']).'kb/s' : '';
                        $us = isset($rec['uspeed']) ? intval($rec['uspeed']).'kb/s' : '';
                        $t0 = isset($rec['timestart']) ? $rec['timestart'] : '';
                        $t1 = isset($rec['timestop']) ? $rec['timestop'] : '';
                        $wd = isset($rec['weekdays']) ? $rec['weekdays'] : '';
                        $mod_time = '';
                        if ($t0 && $t1) { $mod_time = "-m time --timestart $t0 --timestop $t1"; }
                        if ($wd) { $mod_time .= ($mod_time? ' ' : '') . "--weekdays $wd"; $mod_time = trim($mod_time) ? (strpos($mod_time,'-m time')===0? $mod_time : "-m time $mod_time") : $mod_time; }
                        if (!$insert_drule && $iprange && $ds) {
                            $baseD = "-I FORWARD -m iprange --dst-range $iprange -m hashlimit --hashlimit-above $ds --hashlimit-mode dstip --hashlimit-name mulimiter_d$mulid_meta" . ($mod_time? " $mod_time" : '') . " -j DROP";
                            $insert_drule = $baseD;
                            $drule = str_replace('-I ', '-A ', $baseD);
                        }
                        if (!$insert_urule && $iprange && $us) {
                            $baseU = "-I FORWARD -m iprange --src-range $iprange -m hashlimit --hashlimit-above $us --hashlimit-mode srcip --hashlimit-name mulimiter_u$mulid_meta" . ($mod_time? " $mod_time" : '') . " -j DROP";
                            $insert_urule = $baseU;
                            $urule = str_replace('-I ', '-A ', $baseU);
                        }
                    }
                }

                if (!file_exists("$dir/disabled")) { ml_write_file("$dir/disabled", ""); }

                // apply to iptables (both sides if available)
                if ($insert_drule) shell_exec("iptables $insert_drule");
                if ($insert_urule) shell_exec("iptables $insert_urule");

                // add back to save (prepend)
                $old_save = ml_read_file("$dir/save");
                $new_save = trim(($drule ? ($drule . "\n") : '') . ($urule ? ($urule . "\n") : '') . $old_save, "\n");

                // remove from disabled store
                $disabled_rules = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
                $disabled_rules = explode("\n", trim($disabled_rules, "\n"));
                $remain_disabled = '';
                foreach ($disabled_rules as $sv) {
                    if ($sv && $sv != $urule && $sv != $drule) {
                        $remain_disabled .= $sv . "\n";
                    }
                }

                ml_write_file("$dir/save", $new_save);
                ml_write_file("$dir/disabled", trim($remain_disabled, "\n"));

                // update metadata status
                $mulid_a = $drule ? ml_extract_mulid($drule) : ($urule ? ml_extract_mulid($urule) : '');
                if ($mulid_a) { ml_meta_set($mulid_a, ['status' => 'active']); }

                echo json_encode([
                    'success' => true
                ]);
            }
        } elseif ($_GET['act'] == 'disable_range') {
            if (!empty($_POST['iprange'])) {
                $iprange = trim($_POST['iprange']); // format: a.b.c.d-e.f.g.h
                if (!file_exists("$dir/disabled")) { ml_write_file("$dir/disabled", ""); }

                $saved_rules = str_replace("\r\n", "\n", ml_read_file("$dir/save"));
                $saved_arr   = array_filter(explode("\n", trim($saved_rules, "\n")));

                $pairs = [];
                $all_lines = [];
                foreach ($saved_arr as $line) {
                    $all_lines[$line] = true;
                    if (strpos($line, $iprange) !== FALSE && strpos($line, 'mulimiter') !== FALSE) {
                        $parts = explode('--hashlimit-name', $line);
                        if (count($parts) > 1) {
                            $name = trim(explode(' ', $parts[1])[0]);
                            $mulid = str_replace(['mulimiter_d', 'mulimiter_u'], '', $name);
                            if (!isset($pairs[$mulid])) $pairs[$mulid] = ['d' => '', 'u' => ''];
                            if (strpos($name, 'mulimiter_d') !== FALSE) {
                                $pairs[$mulid]['d'] = $line;
                            } else {
                                $pairs[$mulid]['u'] = $line;
                            }
                        }
                    }
                }

                $remain = '';
                foreach ($saved_arr as $line) {
                    $skip = false;
                    foreach ($pairs as $ru) {
                        if (($ru['d'] && $line === $ru['d']) || ($ru['u'] && $line === $ru['u'])) { $skip = true; break; }
                    }
                    if (!$skip) { $remain .= $line . "\n"; }
                }

                $disabled_old = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
                $to_disable = '';
                foreach ($pairs as $ru) {
                    if ($ru['d']) {
                        $delete_drule = str_replace('-A ', '-D ', $ru['d']);
                        shell_exec("iptables $delete_drule");
                        $to_disable .= $ru['d'] . "\n";
                    }
                    if ($ru['u']) {
                        $delete_urule = str_replace('-A ', '-D ', $ru['u']);
                        shell_exec("iptables $delete_urule");
                        $to_disable .= $ru['u'] . "\n";
                    }
                    // update metadata status
                    $mulid_tmp = '';
                    if ($ru['d']) { $mulid_tmp = ml_extract_mulid($ru['d']); }
                    else if ($ru['u']) { $mulid_tmp = ml_extract_mulid($ru['u']); }
                    if ($mulid_tmp) { ml_meta_set($mulid_tmp, ['status' => 'disabled']); }
                }

                ml_write_file("$dir/save", trim($remain, "\n"));
                ml_write_file("$dir/disabled", trim($to_disable . $disabled_old, "\n"));

                echo json_encode(['success' => true]);
            }
        } elseif ($_GET['act'] == 'enable_range') {
            if (!empty($_POST['iprange'])) {
                $iprange = trim($_POST['iprange']); // format: a.b.c.d-e.f.g.h
                if (!file_exists("$dir/disabled")) {
                    shell_exec("touch $dir/disabled");
                }

                $disabled_rules = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
                $disabled_arr   = array_filter(explode("\n", trim($disabled_rules, "\n")));

                $pairs = [];
                foreach ($disabled_arr as $line) {
                    if (strpos($line, $iprange) !== FALSE && strpos($line, 'mulimiter') !== FALSE) {
                        $parts = explode('--hashlimit-name', $line);
                        if (count($parts) > 1) {
                            $name = trim(explode(' ', $parts[1])[0]);
                            $mulid = str_replace(['mulimiter_d', 'mulimiter_u'], '', $name);
                            if (!isset($pairs[$mulid])) $pairs[$mulid] = ['d' => '', 'u' => ''];
                            if (strpos($name, 'mulimiter_d') !== FALSE) {
                                $pairs[$mulid]['d'] = $line;
                            } else {
                                $pairs[$mulid]['u'] = $line;
                            }
                        }
                    }
                }

                // apply and rebuild disabled list
                $new_disabled = '';
                foreach ($disabled_arr as $line) {
                    $is_target = false;
                    foreach ($pairs as $ru) {
                        if (($ru['d'] && $line === $ru['d']) || ($ru['u'] && $line === $ru['u'])) { $is_target = true; break; }
                    }
                    if (!$is_target) { $new_disabled .= $line . "\n"; }
                }

                $old_save = ml_read_file("$dir/save");
                $new_save = trim($old_save, "\n");
                foreach ($pairs as $ru) {
                    $mulid_tmp = '';
                    if ($ru['d']) { $mulid_tmp = ml_extract_mulid($ru['d']); }
                    else if ($ru['u']) { $mulid_tmp = ml_extract_mulid($ru['u']); }
                    // reconstruct missing side using metadata
                    if ($mulid_tmp && (!$ru['d'] || !$ru['u'])) {
                        $meta_all = ml_meta_read();
                        if (isset($meta_all[$mulid_tmp])) {
                            $rec = $meta_all[$mulid_tmp];
                            $iprange = isset($rec['iprange']) ? $rec['iprange'] : '';
                            $ds = isset($rec['dspeed']) ? intval($rec['dspeed']).'kb/s' : '';
                            $us = isset($rec['uspeed']) ? intval($rec['uspeed']).'kb/s' : '';
                            $t0 = isset($rec['timestart']) ? $rec['timestart'] : '';
                            $t1 = isset($rec['timestop']) ? $rec['timestop'] : '';
                            $wd = isset($rec['weekdays']) ? $rec['weekdays'] : '';
                            $mod_time = '';
                            if ($t0 && $t1) { $mod_time = "-m time --timestart $t0 --timestop $t1"; }
                            if ($wd) { $mod_time .= ($mod_time? ' ' : '') . "--weekdays $wd"; $mod_time = trim($mod_time) ? (strpos($mod_time,'-m time')===0? $mod_time : "-m time $mod_time") : $mod_time; }
                            if (!$ru['d'] && $iprange && $ds) {
                                $ru['d'] = "-A FORWARD -m iprange --dst-range $iprange -m hashlimit --hashlimit-above $ds --hashlimit-mode dstip --hashlimit-name mulimiter_d$mulid_tmp" . ($mod_time? " $mod_time" : '') . " -j DROP";
                            }
                            if (!$ru['u'] && $iprange && $us) {
                                $ru['u'] = "-A FORWARD -m iprange --src-range $iprange -m hashlimit --hashlimit-above $us --hashlimit-mode srcip --hashlimit-name mulimiter_u$mulid_tmp" . ($mod_time? " $mod_time" : '') . " -j DROP";
                            }
                        }
                    }
                    if ($ru['d']) { $insert_drule = str_replace('-A ', '-I ', $ru['d']); shell_exec("iptables $insert_drule"); $new_save = trim($ru['d'] . "\n" . $new_save, "\n"); }
                    if ($ru['u']) { $insert_urule = str_replace('-A ', '-I ', $ru['u']); shell_exec("iptables $insert_urule"); $new_save = trim($ru['u'] . "\n" . $new_save, "\n"); }
                    // update metadata status
                    if ($mulid_tmp) { ml_meta_set($mulid_tmp, ['status' => 'active']); }
                }

                ml_write_file("$dir/save", $new_save);
                ml_write_file("$dir/disabled", trim($new_disabled, "\n"));

                echo json_encode(['success' => true]);
            }
        } elseif ($_GET['act'] == 'edit') {

            if ($_POST['drule'] && $_POST['urule'] && $_POST['iprange0'] && $_POST['dspeed'] && $_POST['uspeed']) {

                //add first
                $iprange = $_POST['iprange0'] . '-' . ($_POST['iprange1'] ? $_POST['iprange1'] : $_POST['iprange0']);
                $uspeed   = $_POST['uspeed'] . 'kb/s';
                $dspeed   = $_POST['dspeed'] . 'kb/s';

                $timestart = $_POST['timestart'];
                $timestop = $_POST['timestop'];

                $mod_time = '';
                if ($timestart && $timestop) {
                    $mod_time = "-m time --timestart $timestart --timestop $timestop";
                }

                $arr_weekdays = $_POST['weekdays'];
                if ($arr_weekdays) {
                    $weekdays = implode(',', $arr_weekdays);
                    if ($mod_time) {
                        $mod_time .= " --weekdays $weekdays";
                    } else {
                        $mod_time .= "-m time --weekdays $weekdays";
                    }
                }

                $mulid = dechex(time());
                $uname = 'mulimiter_u' . $mulid;
                $dname = 'mulimiter_d' . $mulid;

                //APPLY
                //download
                shell_exec("iptables -I FORWARD -m iprange --dst-range $iprange -m hashlimit --hashlimit-above $dspeed --hashlimit-mode dstip --hashlimit-name $dname $mod_time -j DROP");
                //upload
                shell_exec("iptables -I FORWARD -m iprange --src-range $iprange -m hashlimit --hashlimit-above $uspeed --hashlimit-mode srcip --hashlimit-name $uname $mod_time -j DROP");
                //END APPLY

                $list = shell_exec('iptables -S');
                $list = str_replace("\r\n", "\n", $list);
                $list = explode("\n", $list);
                $limiters = [];
                $dcurrent = '';
                $ucurrent = '';
                foreach ($list as $ls) {
                    if (strpos($ls, 'mulimiter') !== FALSE) {
                        $limiters[] = $ls;
                    }
                    if (strpos($ls, $dname) !== FALSE) {
                        $dcurrent = $ls;
                    }
                    if (strpos($ls, $uname) !== FALSE) {
                        $ucurrent = $ls;
                    }
                }
                if ($dcurrent && $ucurrent) {
                    $old    = ml_read_file("$dir/save");
                    $new    = trim($dcurrent . "\n" . $old, "\n");
                    $new    = trim($ucurrent . "\n" . $new, "\n");

                    ml_write_file("$dir/save", $new);
                    //end of add }

                    // persist metadata for new rules
                    ml_meta_set($mulid, [
                        'iprange' => $iprange,
                        'dspeed' => intval($_POST['dspeed']),
                        'uspeed' => intval($_POST['uspeed']),
                        'timestart' => $timestart,
                        'timestop' => $timestop,
                        'weekdays' => $weekdays,
                        'status' => 'active'
                    ]);

                    //then delete {
                    $drule           = base64_decode($_POST['drule']);
                    $urule           = base64_decode($_POST['urule']);

                    $delete_drule    = str_replace('-A ', '-D ', $drule);
                    $delete_urule    = str_replace('-A ', '-D ', $urule);

                    $saved_rules    = str_replace("\r\n", "\n", ml_read_file("$dir/save"));
                    $saved_rules    = explode("\n", $saved_rules);
                    $untouched      = '';
                    foreach ($saved_rules as $sv) {
                        if ($sv != $urule && $sv != $drule) {
                            $untouched .= $sv . "\n";
                        }
                    }

                    $untouched = trim($untouched, "\n");

                    shell_exec("iptables $delete_drule");
                    shell_exec("iptables $delete_urule");

                    ml_write_file("$dir/save", $untouched);
                    // remove old metadata
                    $old_mulid = $drule ? ml_extract_mulid($drule) : ($urule ? ml_extract_mulid($urule) : '');
                    if ($old_mulid) { ml_meta_remove($old_mulid); }
                    //end of delete

                    echo json_encode([
                        'success' => true
                    ]);
                }
            }
        } elseif ($_GET['act'] == 'password') {
            $password       = $_POST['password'];
            $new_password   = $_POST['new_password'];
            $new_password2  = $_POST['new_password2'];

            if ($new_password == $new_password2) {
                $hash = base64_decode(trim(shell_exec("cat $dir/.userpass"), "\n"));
                if (password_verify($password, $hash)) {
                    $_SESSION[$app_name]['logedin'] = true;
                    shell_exec("echo \"" . base64_encode(password_hash($new_password, PASSWORD_BCRYPT)) . "\" > $dir/.userpass");
                    echo json_encode([
                        'success' => true
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid password.'
                    ]);
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => "Password doesn't match."
                ]);
            }
        } elseif ($_GET['act'] == 'logout') {
            unset($_SESSION[$app_name]);
            echo json_encode([
                'success' => true
            ]);
        } elseif ($_GET['act'] == 'uninstalldanhancurkan') {
            unset($_SESSION[$app_name]);
            shell_exec("rm -rf $dir");
            $custom_firewall = shell_exec("cat /etc/firewall.user");
            if (strpos($custom_firewall, '/root/.mulimiter/run') !== FALSE) {
                shell_exec("echo \"" . trim(str_replace('/root/.mulimiter/run', '', $custom_firewall), "\n") . "\n" . "\" > /etc/firewall.user");
            }
            $list = shell_exec('iptables -S');
            $list = str_replace("\r\n", "\n", $list);
            $list = explode("\n", $list);
            foreach ($list as $ls) {
                if (strpos($ls, 'mulimiter') !== FALSE) {
                    $delete_rule    = str_replace('-A ', '-D ', $ls);
                    shell_exec("iptables $delete_rule");
                }
            }
            echo json_encode([
                'success' => true,
            ]);
            shell_exec("rm -rf /www/mulimiter");
        } elseif ($_GET['act'] == 'bulk') {
            // Bulk operations: op=disable|enable|delete with arrays drules[] and urules[] (base64 encoded)
            $op = isset($_POST['op']) ? $_POST['op'] : '';
            $drules = isset($_POST['drules']) ? $_POST['drules'] : [];
            $urules = isset($_POST['urules']) ? $_POST['urules'] : [];
            if (!is_array($drules) && $drules !== '' && $drules !== null) $drules = [$drules];
            if (!is_array($urules) && $urules !== '' && $urules !== null) $urules = [$urules];

            if ($op && (count($drules) || count($urules))) {
                if (!file_exists("$dir/disabled")) { ml_write_file("$dir/disabled", ""); }
                $max = max(count($drules), count($urules));
                for ($i = 0; $i < $max; $i++) {
                    $drule_b64 = isset($drules[$i]) ? $drules[$i] : '';
                    $urule_b64 = isset($urules[$i]) ? $urules[$i] : '';
                    if (!$drule_b64 && !$urule_b64) continue;
                    $drule = $drule_b64 ? base64_decode($drule_b64) : '';
                    $urule = $urule_b64 ? base64_decode($urule_b64) : '';

                    if ($op == 'disable') {
                        if ($drule) { $delete_drule = str_replace('-A ', '-D ', $drule); shell_exec("iptables $delete_drule"); }
                        if ($urule) { $delete_urule = str_replace('-A ', '-D ', $urule); shell_exec("iptables $delete_urule"); }
                        // move from save to disabled
                        $saved_rules = str_replace("\r\n", "\n", ml_read_file("$dir/save"));
                        $saved_arr   = explode("\n", trim($saved_rules, "\n"));
                        $remain = '';
                        foreach ($saved_arr as $sv) { if ($sv && $sv != $urule && $sv != $drule) $remain .= $sv."\n"; }
                        $disabled_old = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
                        $to_add = '';
                        if ($drule) $to_add .= $drule."\n";
                        if ($urule) $to_add .= $urule."\n";
                        $disabled_new = trim($to_add.$disabled_old, "\n");
                        ml_write_file("$dir/save", trim($remain, "\n"));
                        ml_write_file("$dir/disabled", $disabled_new);
                    } elseif ($op == 'enable') {
                        // reconstruct missing side using metadata
                        $mulid_meta = $drule ? ml_extract_mulid($drule) : ($urule ? ml_extract_mulid($urule) : '');
                        if ($mulid_meta) {
                            $meta_all = ml_meta_read();
                            if (isset($meta_all[$mulid_meta])) {
                                $rec = $meta_all[$mulid_meta];
                                $iprange = isset($rec['iprange']) ? $rec['iprange'] : '';
                                $ds = isset($rec['dspeed']) ? intval($rec['dspeed']).'kb/s' : '';
                                $us = isset($rec['uspeed']) ? intval($rec['uspeed']).'kb/s' : '';
                                $t0 = isset($rec['timestart']) ? $rec['timestart'] : '';
                                $t1 = isset($rec['timestop']) ? $rec['timestop'] : '';
                                $wd = isset($rec['weekdays']) ? $rec['weekdays'] : '';
                                $mod_time = '';
                                if ($t0 && $t1) { $mod_time = "-m time --timestart $t0 --timestop $t1"; }
                                if ($wd) { $mod_time .= ($mod_time? ' ' : '') . "--weekdays $wd"; $mod_time = trim($mod_time) ? (strpos($mod_time,'-m time')===0? $mod_time : "-m time $mod_time") : $mod_time; }
                                if (!$drule && $iprange && $ds) { $drule = "-A FORWARD -m iprange --dst-range $iprange -m hashlimit --hashlimit-above $ds --hashlimit-mode dstip --hashlimit-name mulimiter_d$mulid_meta" . ($mod_time? " $mod_time" : '') . " -j DROP"; }
                                if (!$urule && $iprange && $us) { $urule = "-A FORWARD -m iprange --src-range $iprange -m hashlimit --hashlimit-above $us --hashlimit-mode srcip --hashlimit-name mulimiter_u$mulid_meta" . ($mod_time? " $mod_time" : '') . " -j DROP"; }
                            }
                        }
                        if ($drule) { $insert_drule = str_replace('-A ', '-I ', $drule); shell_exec("iptables $insert_drule"); }
                        if ($urule) { $insert_urule = str_replace('-A ', '-I ', $urule); shell_exec("iptables $insert_urule"); }
                        // add to save, remove from disabled
                        $old_save = ml_read_file("$dir/save");
                        $new_save = trim(($drule ? ($drule."\n") : '').($urule ? ($urule."\n") : '').$old_save, "\n");
                        $disabled_rules = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
                        $disabled_arr   = explode("\n", trim($disabled_rules, "\n"));
                        $remain_disabled = '';
                        foreach ($disabled_arr as $sv) { if ($sv && $sv != $urule && $sv != $drule) $remain_disabled .= $sv."\n"; }
                        ml_write_file("$dir/save", $new_save);
                        ml_write_file("$dir/disabled", trim($remain_disabled, "\n"));
                        if ($mulid_meta) { ml_meta_set($mulid_meta, ['status' => 'active']); }
                    } elseif ($op == 'delete') {
                        if ($drule) { $delete_drule = str_replace('-A ', '-D ', $drule); shell_exec("iptables $delete_drule"); }
                        if ($urule) { $delete_urule = str_replace('-A ', '-D ', $urule); shell_exec("iptables $delete_urule"); }
                        // remove from save
                        $saved_rules = str_replace("\r\n", "\n", ml_read_file("$dir/save"));
                        $saved_arr   = explode("\n", trim($saved_rules, "\n"));
                        $remain = '';
                        foreach ($saved_arr as $sv) { if ($sv && $sv != $urule && $sv != $drule) $remain .= $sv."\n"; }
                        ml_write_file("$dir/save", trim($remain, "\n"));
                        // also remove from disabled
                        $disabled_rules = str_replace("\r\n", "\n", ml_read_file("$dir/disabled"));
                        $disabled_arr   = explode("\n", trim($disabled_rules, "\n"));
                        $remain_disabled = '';
                        foreach ($disabled_arr as $sv) { if ($sv && $sv != $urule && $sv != $drule) $remain_disabled .= $sv."\n"; }
                        ml_write_file("$dir/disabled", trim($remain_disabled, "\n"));
                    }
                }
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid bulk payload']);
            }
            exit;
        } elseif ($_GET['act'] == 'backup') {
            $save = trim(ml_read_file("$dir/save"), "\n");
            $disabled = trim(ml_read_file("$dir/disabled"), "\n");
            $payload = [
                'version' => trim(@file_get_contents("$dir/version")),
                'generated_at' => date('c'),
                'save' => $save ? explode("\n", $save) : [],
                'disabled' => $disabled ? explode("\n", $disabled) : []
            ];
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        } elseif ($_GET['act'] == 'restore') {
            $payload = isset($_POST['payload']) ? $_POST['payload'] : '';
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
                exit;
            }
            $saveArr = isset($data['save']) && is_array($data['save']) ? $data['save'] : [];
            $disabledArr = isset($data['disabled']) && is_array($data['disabled']) ? $data['disabled'] : [];

            // purge existing mulimiter rules
            $list = shell_exec('iptables -S');
            $list = str_replace("\r\n", "\n", $list);
            $lines = explode("\n", $list);
            foreach ($lines as $ls) {
                if (strpos($ls, 'mulimiter') !== FALSE) {
                    $delete = str_replace('-A ', '-D ', $ls);
                    shell_exec("iptables $delete");
                }
            }
            // write files
            ml_write_file("$dir/save", implode("\n", $saveArr));
            ml_write_file("$dir/disabled", implode("\n", $disabledArr));
            // re-apply save rules
            foreach ($saveArr as $rule) {
                $rule = trim($rule);
                if (!$rule) continue;
                $insert = str_replace('-A ', '-I ', $rule);
                shell_exec("iptables $insert");
            }
            echo json_encode(['success' => true]);
            exit;
        } elseif ($_GET['act'] == 'rebuild_meta') {
            ml_meta_rebuild();
            echo json_encode(['success' => true]);
            exit;
        }
        exit;
    }
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MulImiter</title>
        <link rel="stylesheet" href="asset/bootstrap.min.css">
        <link rel="stylesheet" href="asset/app.css">
        <script src="asset/jquery.min.js"></script>
    </head>

    <body>
        <div class="wraper container py-4 bg-white px-3 rounded shadow-sm" style="max-width: 980px;">
            <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:.5rem;">
                <div>
                    <h1 class="mb-0">MulImiter</h1>
                    <small class="text-muted">The GUI bandwidth limiter for iptables-mod-hashlimit</small>
                </div>
                <div class="d-flex align-items-center" style="gap:.5rem;">
                    <button type="button" class="btn btn-sm btn-toggle-theme" id="btnTheme" onclick="toggleTheme()">Theme: Auto</button>
                </div>
            </div>
            <hr class="mt-3 mb-2">
            <?php
            $list_nav = shell_exec('iptables -S');
            $list_nav = str_replace("\r\n", "\n", $list_nav);
            preg_match_all('/mulimiter_d/', $list_nav, $mm);
            $active_count = count($mm[0]);
            $disabled_raw = file_exists("$dir/disabled") ? str_replace("\r\n", "\n", file_get_contents("$dir/disabled")) : '';
            preg_match_all('/mulimiter_d/', $disabled_raw, $mdm);
            $disabled_count = count($mdm[0]);
            ?>
            <div class="mb-3 d-flex flex-wrap justify-content-center app-nav" style="gap: .5rem;">
                <button id="navHome" onclick="showHome()" class="btn btn-success btn-sm nav-active">🏠 Home <span class="badge" id="badgeActive"><?= $active_count ?></span></button>
                <button id="navSetting" onclick="showSetting()" class="btn btn-info btn-sm">⚙️ Setting</button>
                <button id="navDocs" onclick="showDocs()" class="btn btn-primary btn-sm">📘 Docs</button>
                <button id="navAbout" onclick="showAbout()" class="btn btn-warning btn-sm">ℹ️ About <span class="badge" id="badgeDisabled"><?= $disabled_count ?></span></button>
                <button id="navLogout" onclick="logout()" class="btn btn-danger btn-sm">⎋ Logout</button>
            </div>
            <div id="home-page">
                <form method="post" id="mulimiterFormAdd">
                    <table class="table table-sm table-borderless">
                        <tbody>
                            <tr>
                                <td>IP/Range</td>
                                <td>:</td>
                                <td>
                                    <div class="d-flex" style="align-items: center; ">
                                        <input class="form-control form-control-sm" name="iprange0" placeholder="ex: 10.0.0.1" required>
                                        &nbsp;-&nbsp;
                                        <input name="iprange1" class="form-control form-control-sm" placeholder="ex: 10.0.0.100 (optional)">
                                    </div>
                                </td>

                            </tr>
                            <tr>
                                <td>D Speed</td>
                                <td>:</td>
                                <td>
                                    <div class="d-flex" style="align-items: center; ">
                                        <input class="form-control form-control-sm w-25" type="number" name="dspeed" required> &nbsp;&nbsp;kB/s
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>U Speed</td>
                                <td>:</td>
                                <td>
                                    <div class="d-flex" style="align-items: center; ">
                                        <input class="form-control form-control-sm w-25" type="number" name="uspeed" required> &nbsp;&nbsp;kB/s
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Time</td>
                                <td>:</td>
                                <td>
                                    <div class="d-flex" style="align-items: center; ">
                                        <input class="form-control form-control-sm" style="width: 100%; max-width:140px" type="time" name="timestart">
                                        &nbsp;-&nbsp;
                                        <input class="form-control form-control-sm" style="width: 100%; max-width:140px" type="time" name="timestop">
                                        &nbsp;&nbsp;<span class="d-none d-lg-block"><i>If emptied, it will work all time.</i></span>
                                    </div>
                                    <span class="d-lg-none"><i>If emptied, it will work all time.</i></span>
                                </td>
                            </tr>
                            <tr>
                                <td>Day</td>
                                <td>:</td>
                                <td>
                                    <i>For everyday, left them unchecked or check them all.</i><br>
                                    <input name="weekdays[]" type="checkbox" value="Mon"> Monday <br>
                                    <input name="weekdays[]" type="checkbox" value="Tue"> Tuesday <br>
                                    <input name="weekdays[]" type="checkbox" value="Wed"> Wednesday <br>
                                    <input name="weekdays[]" type="checkbox" value="Thu"> Thursday <br>
                                    <input name="weekdays[]" type="checkbox" value="Fri"> Friday <br>
                                    <input name="weekdays[]" type="checkbox" value="Sat"> Saturday <br>
                                    <input name="weekdays[]" type="checkbox" value="Sun"> Sunday <br>
                                </td>
                            </tr>
                            <tr>
                                <td></td>
                                <td></td>
                                <td><input type="submit" class="btn btn-success btn-sm" value="Add"></td>
                            </tr>
                        </tbody>
                    </table>

                </form>
                <hr>
                <div class="table-responsive">
                <table class="table table-sm table-bordered text-center align-middle">
                    <thead>
                        <th><input type="checkbox" id="selAllActive"></th>
                        <th>IP/Range</th>
                        <th>D Speed</th>
                        <th>U Speed</th>
                        <th>Time</th>
                        <th>Days</th>
                        <th>Action</th>
                    </thead>
                    <tbody>
                        <?php
                        $list = shell_exec('iptables -S');
                        $list = str_replace("\r\n", "\n", $list);
                        $list = explode("\n", $list);
                        $limiters = [];
                        foreach ($list as $i => $ls) {
                            $mulid = '';
                            if (strpos($ls, 'mulimiter_d') !== FALSE) {
                                $rule       = explode(' ', $ls);

                                $iprange    = $rule[5];
                                $iprange    = explode('-', $iprange);
                                $iprange    = $iprange[0] . ($iprange[1] != $iprange[0] ? ' - ' . $iprange[1] : '');
                                $dspeed     = $rule[9];

                                $weekdays = "Everyday";
                                $time = "All time";
                                $time0 = '';
                                foreach ($rule as $i => $rl) {
                                    if ($rl == '--weekdays') {
                                        $weekdays = $rule[$i + 1];
                                        $days = explode(',', $weekdays);
                                        if (count($days) == 7)
                                            $weekdays = "Everyday";
                                    } elseif ($rl == "--timestart") {
                                        $time0 = date('H:i', strtotime($rule[$i + 1]));
                                    } elseif ($rl == "--timestop") {
                                        $time1 = date('H:i', strtotime($rule[$i + 1]));
                                    }

                                    //get mulid
                                    if ($rl == '--hashlimit-name') {
                                        $mulid = str_replace('mulimiter_d', 'mulimiter_u', $rule[$i + 1]);
                                    }
                                }

                                if ($time0) {
                                    $time = $time0 . ' - ' . $time1;
                                }

                                $filter_mulid   = preg_quote($mulid, '~');
                                $upload_rule    = preg_grep('~' . $filter_mulid . '~', $list);
                                foreach ($upload_rule as $upload_rule);
                                $urule          = $upload_rule;
                                $xurule         = explode(' ', $upload_rule);
                                $uspeed         = $xurule[9];
                        ?>
                                <tr>
                                <td>
                                    <input type="checkbox" class="sel-active" data-drule="<?= base64_encode($ls) ?>" data-urule="<?= base64_encode($urule) ?>">
                                </td>
                                <td>
                                    <span id="textIpRange_<?= $i ?>"><?= $iprange ?></span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm ms-1" onclick="disableRange(this)" title="Disable all rules for this range">⛔ Disable Range</button>
                                </td>
                                    <td><span id="textDSpeed_<?= $i ?>"><?= str_replace('kb', ' kB', $dspeed) ?></span></td>
                                    <td><span id="textUSpeed_<?= $i ?>"><?= str_replace('kb', ' kB', $uspeed) ?></span></td>
                                    <td><span id="textTime_<?= $i ?>"><?= $time ?></span></td>
                                    <td><span id="textWeekdays_<?= $i ?>"><?= $weekdays ?></span></td>
                                    <td>
                                        <button type="button" class="btn btn-success btn-sm" data-drule="<?= base64_encode($ls) ?>" data-urule="<?= base64_encode($urule) ?>" onclick="editRule(this)">✎ Edit</button>
                                        <button type="button" class="btn btn-secondary btn-sm" data-drule="<?= base64_encode($ls) ?>" data-urule="<?= base64_encode($urule) ?>" onclick="disableRule(this)">⏸ Disable</button>
                                        <button type="button" class="btn btn-danger btn-sm" data-drule="<?= base64_encode($ls) ?>" data-urule="<?= base64_encode($urule) ?>" onclick="deleteRule(this)">🗑 Delete</button>
                                    </td>
                                </tr>
                        <?php }
                        }
                        ?>
                    </tbody>
                </table>
                </div>
                <div class="my-2">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="bulkDisableActive()">Disable Selected</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="bulkDeleteActive()">Delete Selected</button>
                </div>
                <hr>
                <h5>Disabled Rules</h5>
                <p class="text-muted small">Rows marked <span class="badge warn">Partial</span> contain only download or only upload rule.</p>
                <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selAllDisabled"></th>
                            <th>IP/Range</th>
                            <th>D Speed</th>
                            <th>U Speed</th>
                            <th>Time</th>
                            <th>Days</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $disabled_exists = file_exists("$dir/disabled");
                        $disabled_list = $disabled_exists ? str_replace("\r\n", "\n", ml_read_file("$dir/disabled")) : '';
                        $disabled_arr = array_filter(explode("\n", trim($disabled_list, "\n")));
                        // index by mulid for pairing
                        $pairs = [];
                        $meta_all = ml_meta_read();
                        foreach ($disabled_arr as $rule) {
                            if (strpos($rule, 'mulimiter_d') !== FALSE || strpos($rule, 'mulimiter_u') !== FALSE) {
                                // extract mulid from --hashlimit-name
                                $parts = explode('--hashlimit-name', $rule);
                                if (count($parts) > 1) {
                                    $name = trim(explode(' ', $parts[1])[0]);
                                    $mulid = str_replace(['mulimiter_d', 'mulimiter_u'], '', $name);
                                    if (!isset($pairs[$mulid])) $pairs[$mulid] = ['d' => '', 'u' => ''];
                                    if (strpos($name, 'mulimiter_d') !== FALSE) {
                                        $pairs[$mulid]['d'] = $rule;
                                    } else {
                                        $pairs[$mulid]['u'] = $rule;
                                    }
                                }
                            }
                        }

                        $i = 0;
                        foreach ($pairs as $mulid => $ru) {
                            $download_rule = isset($ru['d']) ? $ru['d'] : '';
                            $upload_rule = isset($ru['u']) ? $ru['u'] : '';

                            // parse human-friendly fields similar to active list
                            $iprange = '';
                            $dspeed = '';
                            $uspeed = '';
                            $time = 'All time';
                            $weekdays = '';

                            if ($download_rule && preg_match('/--dst-range ([^ ]+)/', $download_rule, $mDRange)) {
                                $iprange = str_replace('-', ' - ', $mDRange[1]);
                            } elseif ($upload_rule && preg_match('/--src-range ([^ ]+)/', $upload_rule, $mURange)) {
                                $iprange = str_replace('-', ' - ', $mURange[1]);
                            }
                            // prefer metadata for display if available
                            $mulid_key = '';
                            if ($download_rule) { $mulid_key = ml_extract_mulid($download_rule); }
                            else if ($upload_rule) { $mulid_key = ml_extract_mulid($upload_rule); }
                            if ($mulid_key && isset($meta_all[$mulid_key])) {
                                $rec = $meta_all[$mulid_key];
                                if (isset($rec['iprange']) && $rec['iprange']) { $iprange = str_replace('-', ' - ', $rec['iprange']); }
                                if (isset($rec['dspeed'])) { $dspeed = intval($rec['dspeed']).' kB/s'; }
                                if (isset($rec['uspeed'])) { $uspeed = intval($rec['uspeed']).' kB/s'; }
                                if (isset($rec['timestart']) && isset($rec['timestop']) && $rec['timestart'] && $rec['timestop']) {
                                    $time = $rec['timestart'].' - '.$rec['timestop'];
                                }
                                if (isset($rec['weekdays']) && $rec['weekdays']) { $weekdays = $rec['weekdays']; }
                            } else {
                                if ($download_rule && preg_match('/--hashlimit-above ([^ ]+)/', $download_rule, $mD)) {
                                    $dspeed = str_replace('kb', ' kB', $mD[1]);
                                }
                                if ($upload_rule && preg_match('/--hashlimit-above ([^ ]+)/', $upload_rule, $mU)) {
                                    $uspeed = str_replace('kb', ' kB', $mU[1]);
                                }
                            }
                            $tstart = '';
                            $tstop = '';
                            $src_for_time = $download_rule ?: $upload_rule;
                            if ($src_for_time && preg_match('/--timestart ([^ ]+)/', $src_for_time, $mTs)) { $tstart = $mTs[1]; }
                            if ($src_for_time && preg_match('/--timestop ([^ ]+)/', $src_for_time, $mTe)) { $tstop = $mTe[1]; }
                            if ($tstart && $tstop) { $time = $tstart . ' - ' . $tstop; }
                            if ($src_for_time && preg_match('/--weekdays ([^ ]+)/', $src_for_time, $mWd)) { $weekdays = $mWd[1]; }

                            // do not infer D speed from upload; show '-' if download rule missing
                            $is_partial = (!$download_rule || !$upload_rule);
                            $i++;
                        ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="sel-disabled" data-drule="<?= $download_rule ? base64_encode($download_rule) : '' ?>" data-urule="<?= $upload_rule ? base64_encode($upload_rule) : '' ?>">
                                </td>
                                <td>
                                    <span><?= htmlspecialchars($iprange) ?></span><?php if ($is_partial) { ?><span class="badge warn ms-1">Partial</span><?php } ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm ms-1" onclick="enableRange(this)" data-iprange="<?= htmlspecialchars(str_replace(' - ', '-', $iprange)) ?>" title="Enable all rules for this range">✅ Enable Range</button>
                                </td>
                                <td><span><?= htmlspecialchars($dspeed ?: '-') ?></span></td>
                                <td><span><?= htmlspecialchars($uspeed ?: '-') ?></span></td>
                                <td><span><?= htmlspecialchars($time) ?></span></td>
                                <td><span><?= htmlspecialchars($weekdays) ?></span></td>
                                <td>
                                    <button type="button" class="btn btn-primary btn-sm" data-drule="<?= $download_rule ? base64_encode($download_rule) : '' ?>" data-urule="<?= $upload_rule ? base64_encode($upload_rule) : '' ?>" onclick="enableRule(this)">▶ Enable</button>
                                    <button type="button" class="btn btn-danger btn-sm" data-drule="<?= $download_rule ? base64_encode($download_rule) : '' ?>" data-urule="<?= $upload_rule ? base64_encode($upload_rule) : '' ?>" onclick="deleteRule(this)">Delete</button>
                                </td>
                            </tr>
                        <?php }
                        if ($i == 0) { ?>
                            <tr>
                                <td colspan="7" class="text-center"><i>No disabled rules.</i></td>
                            </tr>
                        <?php } ?>
                </tbody>
                </table>
                </div>
            <div class="my-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="bulkEnableDisabled()">Enable Selected</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="bulkDeleteDisabled()">Delete Selected</button>
            </div>
            </div>
            <div class="text-center d-none" id="about-page">
                <h2>About</h2>
                <p>MulImiter, The GUI bandwidth limiter for iptables-mod-hashlimit.</p>
                <p>Required iptables-mod-iprange, iptables-mod-hashlimit.</p>
                <p>Source Code: https://github.com/noobzhax/mulimiter-ext</p>
                <p class="mt-4">
                    <button class="btn btn-danger" onclick="uninstallMe(this)">Uninstall MulImiter</button>
                </p>
            </div>
            <div class="text-center d-none" id="setting-page">
                <h2>Setting</h2>
                <p>Change your password.</p>
                <hr>
                <form id="mulimiterFormPassword">
                    <div class="mb-5">
                        <label class="mb-2">Current Password:</label>
                        <input type="password" name="password" class="form-control mb-3" style="max-width: 400px; margin:auto">
                        <label class="mb-2">New Password:</label>
                        <input type="password" name="new_password" class="form-control mb-3" style="max-width: 400px; margin:auto">
                        <label class="mb-2">Confirm:</label>
                        <input type="password" name="new_password2" class="form-control mb-3" style="max-width: 400px; margin:auto">
                        <input type="submit" class="btn btn-success" value="Change">
                    </div>
                </form>
                <hr>
                <h3>Backup & Restore</h3>
                <div class="mb-3">
                    <button class="btn btn-outline-primary btn-sm" onclick="downloadBackup()" type="button">Download Backup (JSON)</button>
                </div>
                <div class="mb-3">
                    <label class="mb-2">Restore from backup (JSON file):</label>
                    <input type="file" id="restoreFile" accept="application/json,.json" class="form-control" style="max-width: 420px; margin:auto">
                    <button class="btn btn-outline-danger btn-sm mt-2" type="button" onclick="restoreBackup()">Restore</button>
                </div>
                <hr>
                <h3>Maintenance</h3>
                <div class="d-flex flex-wrap justify-content-center" style="gap:.5rem;">
                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="rebuildMeta()">Rebuild Metadata</button>
                    
                </div>
            </div>
            <div class="d-none" id="docs-page">
                <h2 class="text-center">Documentation</h2>
                <div class="row g-3 mt-3">
                    <div class="col-12 col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-light fw-bold">How To Use (English)</div>
                            <div class="card-body small text-start">
                                <ol>
                                    <li>Open <code>/mulimiter</code> in your browser or via LuCI Services → MulImiter.</li>
                                    <li>Add a rule: set IP/range, download/upload speeds (kB/s), optional time and weekdays, then click Add.</li>
                                    <li>Manage rules:
                                        <ul>
                                            <li>Edit to change parameters.</li>
                                            <li>Disable/Enable per rule or per IP range.</li>
                                            <li>Use checkboxes + bulk buttons to disable/enable/delete multiple rules.</li>
                                        </ul>
                                    </li>
                                    <li>Backup & Restore: Setting → Backup & Restore to download JSON or restore from it.</li>
                                    <li>Uninstall from About page if needed.</li>
                                </ol>
                                <p class="mb-1"><b>Notes</b></p>
                                <ul>
                                    <li>Limits use iptables hashlimit and drop on exceed.</li>
                                    <li>Rules persist via firewall hook at <code>/etc/firewall.user</code>.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-light fw-bold">Cara Pakai (Bahasa Indonesia)</div>
                            <div class="card-body small text-start">
                                <ol>
                                    <li>Buka <code>/mulimiter</code> di browser atau lewat LuCI Services → MulImiter.</li>
                                    <li>Tambah aturan: isi IP/rentang IP, kecepatan unduh/unggah (kB/s), waktu dan hari (opsional), lalu klik Add.</li>
                                    <li>Kelola aturan:
                                        <ul>
                                            <li>Edit untuk mengubah parameter.</li>
                                            <li>Disable/Enable per aturan atau per rentang IP.</li>
                                            <li>Gunakan checkbox + tombol bulk untuk disable/enable/delete banyak aturan sekaligus.</li>
                                        </ul>
                                    </li>
                                    <li>Backup & Restore: Setting → Backup & Restore untuk unduh JSON atau restore dari file yang disimpan.</li>
                                    <li>Uninstall tersedia pada halaman About jika diperlukan.</li>
                                </ol>
                                <p class="mb-1"><b>Catatan</b></p>
                                <ul>
                                    <li>Batasan memakai iptables hashlimit dan menjatuhkan paket saat melewati ambang.</li>
                                    <li>Aturan bertahan melalui hook firewall di <code>/etc/firewall.user</code>.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-header bg-light fw-bold">Feature Checklist</div>
                    <div class="card-body small text-start">
                        <div class="row">
                            <div class="col-12 col-md-6">
                                <label class="d-block"><input type="checkbox" checked disabled> Per‑IP download limit</label>
                                <label class="d-block"><input type="checkbox" checked disabled> Per‑IP upload limit</label>
                                <label class="d-block"><input type="checkbox" checked disabled> Time & weekday scheduling</label>
                                <label class="d-block"><input type="checkbox" checked disabled> Toggle enable/disable per rule</label>
                                <label class="d-block"><input type="checkbox" checked disabled> Toggle per IP range</label>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="d-block"><input type="checkbox" checked disabled> Disabled Rules list & actions</label>
                                <label class="d-block"><input type="checkbox" checked disabled> Checkboxes + Select All</label>
                                <label class="d-block"><input type="checkbox" checked disabled> Bulk actions (enable/disable/delete)</label>
                                <label class="d-block"><input type="checkbox" checked disabled> Backup & Restore (JSON)</label>
                                <label class="d-block"><input type="checkbox" checked disabled> Responsive layout (mobile/desktop)</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <hr>
            <p class="text-center">Author: &nbsp;&nbsp;<a href="https://github.com/tegohsx/" target="_blank">Tegohsx</a> &nbsp;|&nbsp; Maintainer: &nbsp;&nbsp;<a href="https://github.com/noobzhax" target="_blank">noobzhax</a></p>
        </div>
        <div class="toast-container" id="toastContainer"></div>
        <script>
            let state = {}
            state.formEditType = 'add'

            if (!inIframe()) {
                $('.wraper').css({
                    maxWidth: '720px'
                })
            }

            function inIframe() {
                try {
                    return window.self !== window.top;
                } catch (e) {
                    return true;
                }
            }

            // Client-side validation for Add/Edit form
            function ipToInt(ip){
                const parts = ip.split('.')
                if (parts.length !== 4) return NaN
                let n = 0
                for (let i=0;i<4;i++){
                    const v = Number(parts[i])
                    if (!Number.isInteger(v) || v < 0 || v > 255) return NaN
                    n = n * 256 + v
                }
                return n
            }
            function isIPv4(s){
                return /^(25[0-5]|2[0-4]\d|1?\d?\d)(\.(25[0-5]|2[0-4]\d|1?\d?\d)){3}$/.test(s)
            }
            function validateLimiterForm(form){
                const ip0 = form.iprange0.value.trim()
                const ip1 = (form.iprange1.value||'').trim()
                if (!isIPv4(ip0)) { showToast('Invalid start IP address','error'); form.iprange0.focus(); return false }
                if (ip1 && !isIPv4(ip1)) { showToast('Invalid end IP address','error'); form.iprange1.focus(); return false }
                if (ip1){
                    const a = ipToInt(ip0), b = ipToInt(ip1)
                    if (!Number.isFinite(a) || !Number.isFinite(b) || b < a) {
                        showToast('End IP must be greater than or equal to start IP','error');
                        form.iprange1.focus();
                        return false
                    }
                }
                const ds = parseInt(form.dspeed.value,10), us = parseInt(form.uspeed.value,10)
                if (!(ds>0) || !(us>0)) { showToast('Speeds must be positive numbers (kB/s)','error'); return false }
                const t0 = (form.timestart.value||'').trim(), t1=(form.timestop.value||'').trim()
                if ((t0 && !t1) || (!t0 && t1)) { showToast('Provide both start and stop time','error'); return false }
                if (t0 && t1 && t0 >= t1) { showToast('Start time must be before stop time','error'); return false }
                return true
            }

            // Toasts
            function showToast(message, type = 'info', title = ''){
                const c = document.getElementById('toastContainer')
                if (!c) return alert(message)
                const el = document.createElement('div')
                el.className = `toast ${type}`
                el.innerHTML = `<div class="title">${title || (type==='success'?'Success': type==='error'?'Error': type==='warn'?'Warning':'Info')}</div><div class="msg"></div>`
                el.querySelector('.msg').textContent = message
                c.appendChild(el)
                requestAnimationFrame(()=> el.classList.add('show'))
                setTimeout(()=>{ el.classList.remove('show'); setTimeout(()=> el.remove(), 200) }, 3000)
            }
            // Replace alert with toast info for non-critical notices
            window.alert = (msg)=> showToast(msg,'info')

            // Theme handling
            const THEME_KEY = 'mulimiter_theme'
            function applyTheme(theme){
                const root = document.documentElement
                root.classList.remove('theme-dark')
                let label = 'Light'
                if(theme === 'dark' || (theme === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)){
                    root.classList.add('theme-dark')
                    label = theme === 'auto' ? 'Auto (Dark)' : 'Dark'
                } else if(theme === 'auto'){
                    label = 'Auto (Light)'
                }
                const btn = document.getElementById('btnTheme');
                if (btn) btn.textContent = 'Theme: ' + label
            }
            function initTheme(){
                let theme = localStorage.getItem(THEME_KEY) || 'auto'
                applyTheme(theme)
                if (window.matchMedia){
                    const mq = window.matchMedia('(prefers-color-scheme: dark)')
                    if (mq.addEventListener) mq.addEventListener('change', () => { if ((localStorage.getItem(THEME_KEY) || 'auto') === 'auto') applyTheme('auto') })
                    else if (mq.addListener) mq.addListener(() => { if ((localStorage.getItem(THEME_KEY) || 'auto') === 'auto') applyTheme('auto') })
                }
            }
            function toggleTheme(){
                const current = localStorage.getItem(THEME_KEY) || 'auto'
                const next = current === 'auto' ? 'light' : (current === 'light' ? 'dark' : 'auto')
                localStorage.setItem(THEME_KEY, next)
                applyTheme(next)
            }
            $(initTheme)

            $("#mulimiterFormAdd").on('submit', function(e) {
                e.preventDefault();
                if (!validateLimiterForm(this)) return;
                if (state.formEditType == 'add') {
                    $(this).find('[type=submit]').val('Adding...').prop('disabled', true)
                    $.ajax({
                        type: 'post',
                        url: '<?= $_SERVER['PHP_SELF'] ?>?act=add',
                        dataType: 'json',
                        cache: false,
                        data: $(this).serialize(),
                        success: r => {
                            if (r.success) {
                                showToast('Rule added','success')
                                setTimeout(()=> location.reload(), 500)
                            } else {
                                showToast(r.message||'Failed to add rule','error')
                                $(this).find('[type=submit]').val('Add').prop('disabled', false)
                            }
                        }
                    })
                } else if (state.formEditType == 'edit') {
                    $(this).find('[type=submit]').val('Saving...').prop('disabled', true)
                    let fdata = new FormData(this)
                    fdata.append('drule', state.drule)
                    fdata.append('urule', state.urule)
                    $.ajax({
                        type: 'post',
                        url: '<?= $_SERVER['PHP_SELF'] ?>?act=edit',
                        dataType: 'json',
                        cache: false,
                        data: fdata,
                        contentType: false,
                        processData: false,
                        success: r => {
                            if (r.success) {
                                showToast('Rule saved','success')
                                setTimeout(()=> location.reload(), 500)
                            } else {
                                showToast(r.message||'Failed to save rule','error')
                                $(this).find('[type=submit]').val('Save').prop('disabled', false)
                            }
                        }
                    })
                }
            })

            $("#mulimiterFormAdd").on('click', '#btnCancelEdit', function() {
                state.formEditType = 'add'
                $('#mulimiterFormAdd').find('[type=submit]').val('Add')
                $("#mulimiterFormAdd")[0].reset()
                $(this).remove()
            })

            $("#mulimiterFormPassword").on('submit', function(e) {
                e.preventDefault();
                $(this).find('[type=submit]').val('Changing...').prop('disabled', true)
                $.ajax({
                    type: 'post',
                    url: '<?= $_SERVER['PHP_SELF'] ?>?act=password',
                    dataType: 'json',
                    cache: false,
                    data: $(this).serialize(),
                    success: r => {
                        if (r.success) {
                            showToast('Password changed','success')
                            $("#mulimiterFormPassword")[0].reset()
                            $(this).find('[type=submit]').val('Change').prop('disabled', false)
                        } else {
                            showToast(r.message||'Failed to change password','error')
                            $(this).find('[type=submit]').val('Change').prop('disabled', false)
                        }
                    }
                })
            })

            function editRule(el) {
                state.formEditType = 'edit'
                state.drule = $(el).attr('data-drule')
                state.urule = $(el).attr('data-urule')
                let iprange = $(el).closest('tr').find('[id^=textIpRange_]').text().split(' - ')
                let iprange0 = iprange[0],
                    iprange1 = iprange[1] || '',
                    dspeed = parseInt($(el).closest('tr').find('[id^=textDSpeed_]').text()),
                    uspeed = parseInt($(el).closest('tr').find('[id^=textUSpeed_]').text()),
                    time_ = $(el).closest('tr').find('[id^=textTime_]').text().split(' - '),
                    timestart = time_[0] == 'All time' ? '' : (time_[0] || ''),
                    timestop = time_[1] || '',
                    weekdays = $(el).closest('tr').find('[id^=textWeekdays_]').text().split(',')

                $('#mulimiterFormAdd').find('[name=iprange0]').val(iprange0).focus()
                $('#mulimiterFormAdd').find('[name=iprange1]').val(iprange1)
                $('#mulimiterFormAdd').find('[name=dspeed]').val(dspeed)
                $('#mulimiterFormAdd').find('[name=uspeed]').val(uspeed)
                $('#mulimiterFormAdd').find('[name=timestart]').val(timestart)
                $('#mulimiterFormAdd').find('[name=timestop]').val(timestop)

                $('#mulimiterFormAdd').find('[name^=weekdays]').prop('checked', false)
                weekdays.forEach(v => {
                    $('#mulimiterFormAdd').find('[name^=weekdays][value=' + v + ']').prop('checked', true)
                })

                if (!$('#mulimiterFormAdd').find('#btnCancelEdit').length) {
                    $('#mulimiterFormAdd').find('[type=submit]').val('Save').after(`<input id="btnCancelEdit" type="reset" class="btn btn-danger btn-sm ms-1" value="Cancel">`)
                }

            }

            function deleteRule(el) {
                if (confirm('Delete this rule?')) {
                    let drule = $(el).attr('data-drule') || ''
                    let urule = $(el).attr('data-urule') || ''
                    $.ajax({
                        url: '<?= $_SERVER['PHP_SELF'] ?>?act=delete',
                        type: 'post',
                        dataType: 'json',
                        cache: false,
                        data: {
                            urule,
                            drule
                        },
                        success: r => { if (r.success) { showToast('Rule deleted','success'); setTimeout(()=> location.reload(), 400) } }
                    })
                }
            }

            function disableRule(el) {
                if (confirm('Disable this rule?')) {
                    let drule = $(el).attr('data-drule')
                    let urule = $(el).attr('data-urule')
                    $.ajax({
                        url: '<?= $_SERVER['PHP_SELF'] ?>?act=disable',
                        type: 'post',
                        dataType: 'json',
                        cache: false,
                        data: { urule, drule },
                        success: r => { if (r.success) { showToast('Rule disabled','success'); setTimeout(()=> location.reload(), 400) } }
                    })
                }
            }

            function enableRule(el) {
                let drule = $(el).attr('data-drule') || ''
                let urule = $(el).attr('data-urule') || ''
                $.ajax({
                    url: '<?= $_SERVER['PHP_SELF'] ?>?act=enable',
                    type: 'post',
                    dataType: 'json',
                    cache: false,
                    data: { urule, drule },
                    success: r => { if (r.success) { showToast('Rule enabled','success'); setTimeout(()=> location.reload(), 400) } }
                })
            }

            function disableRange(el) {
                const ipText = $(el).closest('tr').find('[id^=textIpRange_]').text().trim()
                const iprange = ipText.replace(/\s+-\s+/,'-')
                if (confirm('Disable all rules for ' + ipText + ' ?')) {
                    $.ajax({
                        url: '<?= $_SERVER['PHP_SELF'] ?>?act=disable_range',
                        type: 'post',
                        dataType: 'json',
                        cache: false,
                        data: { iprange },
                        success: r => { if (r.success) { showToast('Range disabled: '+ipText,'success'); setTimeout(()=> location.reload(), 500) } }
                    })
                }
            }

            function enableRange(el) {
                const iprange = $(el).attr('data-iprange') || ($(el).closest('tr').find('span').first().text().trim().replace(/\s+-\s+/,'-'))
                $.ajax({
                    url: '<?= $_SERVER['PHP_SELF'] ?>?act=enable_range',
                    type: 'post',
                    dataType: 'json',
                    cache: false,
                    data: { iprange },
                    success: r => { if (r.success) { showToast('Range enabled: '+iprange.replace('-', ' - '),'success'); setTimeout(()=> location.reload(), 500) } }
                })
            }

            function uninstallMe(el) {
                if (confirm("Do you want to uninstall MulImiter?")) {
                    if (confirm("Yakin, nih, nggak nyesel?")) {
                        $.ajax({
                            type: 'post',
                            url: '<?= $_SERVER['PHP_SELF'] ?>?act=uninstalldanhancurkan',
                            data: "uninstall=true",
                            dataType: 'json',
                            success: r => {
                                if (r.success) {
                                    showToast('Uninstalled','success'); setTimeout(()=> location.reload(), 600)
                                }
                            }
                        })
                    }
                }
            }

            function logout() {
                $.ajax({
                    url: '<?= $_SERVER['PHP_SELF'] ?>?act=logout',
                    type: 'post',
                    dataType: 'json',
                    cache: false,
                    data: 'logout=true',
                    success: r => {
                        if (r.success) { showToast('Logged out','success'); setTimeout(()=> location.reload(), 300) }
                    }
                })
            }

function showHome() {
                if ($('#home-page').attr('class').includes('d-none')) {
                    $('.app-nav .btn').removeClass('nav-active');
                    $('#navHome').addClass('nav-active');
                    $('#about-page').addClass('d-none');
                    $('#docs-page').addClass('d-none');
                    $('#setting-page').addClass('d-none');
                    $('#home-page').removeClass('d-none');
                }
            }


            function showAbout() {
                if ($('#about-page').attr('class').includes('d-none')) {
                    $('.app-nav .btn').removeClass('nav-active');
                    $('#navAbout').addClass('nav-active');
                    $('#home-page').addClass('d-none');
                    $('#docs-page').addClass('d-none');
                    $('#setting-page').addClass('d-none');
                    $('#about-page').removeClass('d-none');
                }
            }

            function showSetting() {
                if ($('#setting-page').attr('class').includes('d-none')) {
                    $('.app-nav .btn').removeClass('nav-active');
                    $('#navSetting').addClass('nav-active');
                    $('#home-page').addClass('d-none');
                    $('#docs-page').addClass('d-none');
                    $('#about-page').addClass('d-none');
                    $('#setting-page').removeClass('d-none');
                }
            }

            function showDocs() {
                if ($('#docs-page').attr('class').includes('d-none')) {
                    $('.app-nav .btn').removeClass('nav-active');
                    $('#navDocs').addClass('nav-active');
                    $('#home-page').addClass('d-none');
                    $('#about-page').addClass('d-none');
                    $('#setting-page').addClass('d-none');
                    $('#docs-page').removeClass('d-none');
                }
            }

            function downloadBackup() {
                $.ajax({
                    url: '<?= $_SERVER['PHP_SELF'] ?>?act=backup',
                    type: 'post',
                    dataType: 'json',
                    success: data => {
                        const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'})
                        const a = document.createElement('a')
                        a.href = URL.createObjectURL(blob)
                        a.download = `mulimiter-backup-${new Date().toISOString().replace(/[:]/g,'-')}.json`
                        document.body.appendChild(a)
                        a.click()
                        URL.revokeObjectURL(a.href)
                        a.remove()
                    }
                })
            }

            function restoreBackup() {
                const f = document.getElementById('restoreFile').files[0]
                if (!f) { showToast('Choose a backup file first.','warn'); return }
                const reader = new FileReader()
                reader.onload = function() {
                    $.ajax({
                        url: '<?= $_SERVER['PHP_SELF'] ?>?act=restore',
                        type: 'post',
                        dataType: 'json',
                        data: { payload: reader.result },
                        success: r => {
                            if (r.success) { showToast('Restore successful.','success'); setTimeout(()=> location.reload(), 500) }
                            else { showToast(r.message || 'Restore failed.','error') }
                        }
                    })
                }
                reader.readAsText(f)
            }

            function rebuildMeta(){
                $.ajax({
                    url: '<?= $_SERVER['PHP_SELF'] ?>?act=rebuild_meta',
                    type: 'post',
                    dataType: 'json',
                    success: r => { if (r.success) { showToast('Metadata rebuilt.','success'); setTimeout(()=> location.reload(), 500) } else showToast(r.message||'Failed to rebuild.','error') }
                })
            }

            

            // Select all handlers
            $(document).on('change', '#selAllActive', function(){
                $('.sel-active').prop('checked', this.checked)
            })
            $(document).on('change', '#selAllDisabled', function(){
                $('.sel-disabled').prop('checked', this.checked)
            })

            function collectSelected(cls){
                const drules = []
                const urules = []
                $(cls+':checked').each(function(){
                    drules.push($(this).attr('data-drule'))
                    urules.push($(this).attr('data-urule'))
                })
                return {drules, urules}
            }

            function bulkPost(op, sel, onok){
                if (!sel.drules.length && !sel.urules.length) { showToast('No items selected.','warn'); return }
                $.ajax({
                    url: '<?= $_SERVER['PHP_SELF'] ?>?act=bulk',
                    type: 'post',
                    dataType: 'json',
                    cache: false,
                    data: Object.assign({op}, { 'drules[]': sel.drules, 'urules[]': sel.urules }),
                    success: r => { if (r.success) { if (onok) onok(); setTimeout(()=> location.reload(), 500) } else showToast(r.message || 'Failed','error') }
                })
            }

            function bulkDisableActive(){
                const sel = collectSelected('.sel-active')
                const c1 = Math.max(sel.drules.length, sel.urules.length); if (confirm('Disable '+c1+' selected rules?')) bulkPost('disable', sel, ()=> showToast('Disabled '+c1+' rules','success'))
            }
            function bulkDeleteActive(){
                const sel = collectSelected('.sel-active')
                const c2 = Math.max(sel.drules.length, sel.urules.length); if (confirm('Delete '+c2+' selected rules?')) bulkPost('delete', sel, ()=> showToast('Deleted '+c2+' rules','success'))
            }
            function bulkEnableDisabled(){
                const sel = collectSelected('.sel-disabled')
                const c3 = Math.max(sel.drules.length, sel.urules.length); bulkPost('enable', sel, ()=> showToast('Enabled '+c3+' rules','success'))
            }
            function bulkDeleteDisabled(){
                const sel = collectSelected('.sel-disabled')
                const c4 = Math.max(sel.drules.length, sel.urules.length); if (confirm('Delete '+c4+' selected rules?')) bulkPost('delete', sel, ()=> showToast('Deleted '+c4+' rules','success'))
            }
        </script>
    </body>

    </html>

<?php } else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'];
        $hash = base64_decode(trim(shell_exec("cat $dir/.userpass"), "\n"));
        if (password_verify($password, $hash)) {
            $_SESSION[$app_name]['logedin'] = true;
            echo json_encode([
                'success' => true
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid password.'
            ]);
        }
        exit;
    }
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MulImiter</title>
        <link rel="stylesheet" href="asset/bootstrap.min.css">
        <style>
            .wraper {
                margin: auto;
                max-width: 100%;
            }
        </style>
        <script src="asset/jquery.min.js"></script>
    </head>

<body>
        <div class="wraper container py-5 px-3" style="max-width: 520px;">
            <div class="bg-white rounded shadow-sm p-4 text-start">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="mb-0">MulImiter</h2>
                    <button type="button" class="btn btn-sm btn-toggle-theme" id="btnThemeLogin" onclick="toggleTheme()">Theme: Auto</button>
                </div>
                <p class="text-muted mb-4">The GUI bandwidth limiter for iptables-mod-hashlimit</p>
                <form id="mulimiterFormLogin">
                    <label class="mb-2">Enter your Password</label>
                    <input type="password" name="password" class="form-control mb-3" placeholder="••••••••" autofocus>
                    <div class="d-grid">
                        <input type="submit" class="btn btn-success" value="Login">
                    </div>
                </form>
                <hr>
                <p class="text-center mb-0">Author: &nbsp;&nbsp;<a href="https://github.com/tegohsx/" target="_blank">Tegohsx</a> &nbsp;|&nbsp; Maintainer: <a href="https://github.com/noobzhax" target="_blank">noobzhax</a></p>
            </div>
        </div>
        <script>
            // Theme init for login
            (function(){
                const THEME_KEY='mulimiter_theme';
                function applyTheme(theme){
                    const root=document.documentElement; root.classList.remove('theme-dark');
                    let label='Light';
                    if(theme==='dark' || (theme==='auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)){
                        root.classList.add('theme-dark'); label= theme==='auto'?'Auto (Dark)':'Dark';
                    } else if(theme==='auto'){ label='Auto (Light)'; }
                    const btn=document.getElementById('btnThemeLogin'); if(btn) btn.textContent='Theme: '+label;
                }
                function init(){ let t=localStorage.getItem(THEME_KEY)||'auto'; applyTheme(t);
                    if (window.matchMedia){ const mq=window.matchMedia('(prefers-color-scheme: dark)');
                        if(mq.addEventListener) mq.addEventListener('change',()=>{ if((localStorage.getItem(THEME_KEY)||'auto')==='auto') applyTheme('auto'); });
                    }
                }
                window.toggleTheme=function(){ const THEME_KEY='mulimiter_theme'; const cur=localStorage.getItem(THEME_KEY)||'auto'; const nxt= cur==='auto'?'light':(cur==='light'?'dark':'auto'); localStorage.setItem(THEME_KEY,nxt); applyTheme(nxt); };
                init();
            })();
            if (!inIframe()) { $('.wraper').css({ maxWidth: '520px' }) }
            if (!inIframe()) {
                $('.wraper').css({
                    maxWidth: '720px'
                })
            }

            function inIframe() {
                try {
                    return window.self !== window.top;
                } catch (e) {
                    return true;
                }
            }
            $("#mulimiterFormLogin").on('submit', function(e) {
                e.preventDefault();
                $(this).find('[type=submit]').val('Loging in...').prop('disabled', true)
                $.ajax({
                    type: 'post',
                    url: '<?= $_SERVER['PHP_SELF'] ?>',
                    dataType: 'json',
                    cache: false,
                    data: $(this).serialize(),
                    success: r => {
                        if (r.success) {
                            location.reload()
                        } else {
                            alert(r.message)
                            $(this).find('[type=submit]').val('Login').prop('disabled', false)
                        }
                    }
                })
            })
        </script>
    </body>

    </html>
<?php }
