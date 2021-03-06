<?php

namespace app\controller;

class Home extends \app\core\Controller {

    protected function _create_snapshot($view_data) {
        $filename = realpath(
            \app\lib\Library::retrieve('snapshot_directory')
        ) . '/' . date('Y-m-d_H-i') . '.html';

        $contents = $this->render_html('partial/test_results', $view_data);

        $handle = @fopen($filename, 'a');
        if ( !$handle ) {
            return array(
                'type'    => 'failed',
                'title'   => 'Error Creating Snapshot',
                'message' => 'Please ensure that the '
                    . '<code>snapshot_directory</code> in '
                    . '<code>app/config/bootstrap.php</code> exists and '
                    . 'has the proper permissions.'
            );
        }

        fwrite($handle, $contents);
        fclose($handle);
        return array(
            'type'    => 'succeeded',
            'title'   => 'Snapshot Created',
            'message' => "Snapshot can be found at <code>{$filename}</code>."
        );
    }

    protected function _get_paths_from_library_entry($key) {
        $ret_val = \app\lib\Library::retrieve($key);
        if ( !is_array($ret_val) ) {
            $ret_val = array( $ret_val );
        }
        foreach ( $ret_val as $key => &$path ) {
            if (false === ($path = realpath($path))) {
                unset($ret_val[$key]);
            } else {
                $path = str_replace('\\', '/', $path);
            }
        }
        if ( 1 == count($ret_val) ) {
            $ret_val_keys = array_keys($ret_val);
            $ret_val = $ret_val[$ret_val_keys[0]];
        }
        return $ret_val;
    }

    // GET
    public function help($request) {
        return array();
    }

    // GET/POST
    public function index($request) {
        if ( $request->is('get') ) {
            $test_directory = $this->_get_paths_from_library_entry('test_directory');
            $suites = array();
            $stats = array();
            $store_statistics = \app\lib\Library::retrieve('store_statistics');
            $create_snapshots = \app\lib\Library::retrieve('create_snapshots');
            $sandbox_errors = \app\lib\Library::retrieve('sandbox_errors');
            $use_xml = $this->_get_paths_from_library_entry('xml_configuration_file');
            return compact(
                'create_snapshots',
                'sandbox_errors',
                'stats',
                'store_statistics',
                'suites',
                'test_directory',
                'use_xml'
            );
        }

        $tests = explode('|', $request->data['test_files']);
        $vpu = new \app\lib\VPU();

        if ( $request->data['sandbox_errors'] ) {
            error_reporting(\app\lib\Library::retrieve('error_reporting'));
            set_error_handler(array($vpu, 'handle_errors'));
        }

        $xml_config = false;

        $notifications = array();
        if ( $request->data['use_xml'] ) {
            if ( $xml_config = \app\lib\Library::retrieve('xml_configuration_file') ) {
                if ( is_array($xml_config) ) {
                    foreach ( $xml_config as $xml_conf_entry ) {
                        if ( $request->data['use_xml'] === ($real_xml_conf_entry = realpath($xml_conf_entry)) ) {
                            $xml_config = $real_xml_conf_entry;
                            break;
                        }
                    }
                    if ( !is_string($xml_config) ) {
                        $xml_config = false;
                    }
                } else {
                    $xml_config = realpath($xml_config);
                }
            }
            if ( !$xml_config ) {
                $notifications[] = array(
                    'type'    => 'failed',
                    'title'   => 'No Valid XML Configuration File Found',
                    'message' => 'Please ensure that the '
                    . '<code>xml_configuration_file</code> in '
                    . '<code>app/config/bootstrap.php</code> exists and '
                    . 'has the proper permissions.'
                );
            }
        }

        $results = ( $xml_config )
            ? $vpu->run_with_xml($xml_config)
            : $vpu->run_tests($tests);
        $results = $vpu->compile_suites($results, 'web');

        if ( $request->data['sandbox_errors'] ) {
            restore_error_handler();
        }

        $suites = $results['suites'];
        $stats = $results['stats'];
        $errors = $vpu->get_errors();
        $to_view = compact('suites', 'stats', 'errors');

        if ( $request->data['create_snapshots'] ) {
            $notifications[] = $this->_create_snapshot($to_view);
        }
        if ( $request->data['store_statistics'] ) {
            $notifications[] = $this->_store_statistics($stats);
        }

        return $to_view + compact('notifications');
    }

    protected function _store_statistics($stats) {
        $db_options = \app\lib\Library::retrieve('db');
        $db = new $db_options['plugin']();
        if ( !$db->connect($db_options) ) {
            return array(
                'type'    => 'failed',
                'title'   => 'Error Connecting to Database',
                'message' => implode(' ', $db->get_errors())
            );
        }

        $now = date('Y-m-d H:i:s');
        foreach ( $stats as $key => $stat ) {
            $data = array(
                'run_date'   => $now,
                'failed'     => $stat['failed'],
                'incomplete' => $stat['incomplete'],
                'skipped'    => $stat['skipped'],
                'succeeded'  => $stat['succeeded']
            );
            $table = ucfirst(rtrim($key, 's')) . 'Result';
            if ( !$db->insert($table, $data) ) {
                return array(
                    'type'    => 'failed',
                    'title'   => 'Error Inserting Record',
                    'message' => implode(' ', $db->get_errors())
                );
            }
        }

        return array(
            'type'    => 'succeeded',
            'title'   => 'Statistics Stored',
            'message' => 'The statistics generated during this test run were '
                . 'successfully stored.'
        );

    }

}

?>
