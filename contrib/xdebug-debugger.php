#!/usr/bin/env php
<?php
/*
   +----------------------------------------------------------------------+
   | Xdebug                                                               |
   +----------------------------------------------------------------------+
   | Copyright (c) 2002-2024 Derick Rethans                               |
   +----------------------------------------------------------------------+
   | This source file is subject to version 1.01 of the Xdebug license,  |
   | that is bundled with this package in the file LICENSE, and is        |
   | available at through the world-wide-web at                           |
   | https://xdebug.org/license.php                                       |
   | If you did not receive a copy of the Xdebug license and are unable   |
   | to obtain it through the world-wide-web, please send a note to       |
   | derick@xdebug.org so we can mail you a copy immediately.             |
   +----------------------------------------------------------------------+
   | Authors: Xdebug contributors                                         |
   +----------------------------------------------------------------------+

   Xdebug Terminal Debugger
   ========================
   A simple interactive command-line debugger for PHP using the DBGp protocol.

   Usage:
     php xdebug-debugger.php [options] <file.php> [-- <script-args>]

   Options:
     --port=<n>        Port for the DBGp server (default: 9003)
     --php=<path>      Path to the PHP executable (default: auto-detected)
     --help            Show this help message

   Commands during a debug session:
     r, run            Continue execution until the next breakpoint or end
     s, step           Step into the next statement
     n, next           Step over the next statement
     o, out            Step out of the current function
     b <file> <line>   Set a breakpoint  (file defaults to the current file)
     b <line>          Set a breakpoint on <line> of the current file
     bl                List all breakpoints
     bd <id>           Delete breakpoint by ID
     p <expr>          Print/evaluate a PHP expression
     v                 Show local variables in the current scope
     bt                Show the call stack (backtrace)
     l [line] [n]      List source around the current line (or at <line>)
     h, help, ?        Show this command reference
     q, quit           Quit the debugger
*/

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

if ( PHP_MAJOR_VERSION < 7 ) {
    fwrite( STDERR, "xdebug-debugger.php requires PHP 7 or higher.\n" );
    exit( 1 );
}

// Colour helpers (ANSI, disabled when not a TTY or on Windows)
function xd_colors_enabled(): bool {
    return function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );
}

function xd_color( string $code, string $text ): string {
    if ( ! xd_colors_enabled() ) {
        return $text;
    }
    return "\033[{$code}m{$text}\033[0m";
}

function xd_bold( string $t ): string    { return xd_color( '1',     $t ); }
function xd_green( string $t ): string   { return xd_color( '0;32',  $t ); }
function xd_yellow( string $t ): string  { return xd_color( '0;33',  $t ); }
function xd_cyan( string $t ): string    { return xd_color( '0;36',  $t ); }
function xd_red( string $t ): string     { return xd_color( '0;31',  $t ); }
function xd_dim( string $t ): string     { return xd_color( '2',     $t ); }

// -------------------------------------------------------------------------
// Argument parsing
// -------------------------------------------------------------------------

function xd_usage( int $exit_code = 0 ): void {
    echo <<<'HELP'
Xdebug Terminal Debugger

Usage:
  xdebug-debugger.php [options] <file.php> [-- <script-args>]

Options:
  --port=<n>    DBGp listen port (default: 9003)
  --php=<path>  Path to PHP executable (default: auto-detected)
  --help        Show this help

Commands during a debug session:
  r, run              Continue until next breakpoint / end of script
  s, step             Step into the next statement
  n, next             Step over (execute next line, skip function body)
  o, out              Step out of the current function
  b [file] <line>     Set a breakpoint (file defaults to the current file)
  bl                  List all active breakpoints
  bd <id>             Delete breakpoint by ID
  p <expr>            Print / evaluate a PHP expression
  v                   Show local variables
  bt                  Show the call stack
  l [line] [count]    List source around current position (or given line)
  h, help, ?          Show this command reference
  q, quit             Quit the debugger

HELP;
    exit( $exit_code );
}

$opts = [
    'port' => 9003,
    'php'  => null,
    'file' => null,
    'args' => [],
];

$raw_args = $argv;
array_shift( $raw_args ); // remove script name

$separator_pos = array_search( '--', $raw_args );
if ( $separator_pos !== false ) {
    $opts['args'] = array_slice( $raw_args, $separator_pos + 1 );
    $raw_args = array_slice( $raw_args, 0, $separator_pos );
}

foreach ( $raw_args as $arg ) {
    if ( $arg === '--help' || $arg === '-h' ) {
        xd_usage( 0 );
    } elseif ( preg_match( '/^--port=(\d+)$/', $arg, $m ) ) {
        $opts['port'] = (int)$m[1];
    } elseif ( preg_match( '/^--php=(.+)$/', $arg, $m ) ) {
        $opts['php'] = $m[1];
    } elseif ( strpos( $arg, '--' ) !== 0 ) {
        $opts['file'] = $arg;
    }
}

if ( $opts['file'] === null ) {
    fwrite( STDERR, "Error: no PHP file specified.\n\n" );
    xd_usage( 1 );
}

if ( ! file_exists( $opts['file'] ) ) {
    fwrite( STDERR, "Error: file not found: {$opts['file']}\n" );
    exit( 1 );
}

$opts['file'] = realpath( $opts['file'] );

// Locate PHP binary
if ( $opts['php'] === null ) {
    // Use the same binary that is running this script
    $opts['php'] = PHP_BINARY;
}

// -------------------------------------------------------------------------
// DBGp protocol helpers
// -------------------------------------------------------------------------

/**
 * Send a DBGp command through the connection socket.
 * Every command ends with a NUL byte.
 */
function xd_send( $conn, string $command, int $transaction_id ): void {
    $parts = explode( ' ', $command, 2 );
    if ( count( $parts ) === 1 ) {
        $command = $parts[0] . " -i {$transaction_id}";
    } else {
        $command = $parts[0] . " -i {$transaction_id} " . $parts[1];
    }
    fwrite( $conn, $command . "\0" );
}

/**
 * Read one DBGp response (length NUL data NUL) and return the XML string.
 * Returns null on EOF or error.
 */
function xd_read( $conn ): ?string {
    stream_set_timeout( $conn, 10 );

    $length = '';
    while ( true ) {
        $char = fgetc( $conn );
        if ( $char === false ) {
            return null;
        }
        if ( $char === "\0" ) {
            break;
        }
        $length .= $char;
    }

    $length = (int)$length;
    if ( $length <= 0 ) {
        return null;
    }

    $data = '';
    while ( strlen( $data ) < $length ) {
        $chunk = fread( $conn, $length - strlen( $data ) );
        if ( $chunk === false || $chunk === '' ) {
            return null;
        }
        $data .= $chunk;
    }
    fgetc( $conn ); // trailing NUL

    return $data;
}

/**
 * Read one DBGp response and parse to SimpleXMLElement.
 * Skips stream / notify messages, which are handled inline.
 */
function xd_read_response( $conn, ?int $expected_tid = null ): ?SimpleXMLElement {
    while ( true ) {
        $xml_str = xd_read( $conn );
        if ( $xml_str === null ) {
            return null;
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $xml_str );
        if ( $xml === false ) {
            return null;
        }

        $name = $xml->getName();

        // Stream packets (stdout/stderr from the script)
        if ( $name === 'stream' ) {
            $text = base64_decode( (string)$xml );
            echo xd_dim( '[output] ' ) . $text;
            continue;
        }

        // Notify packets (e.g. breakpoint resolved)
        if ( $name === 'notify' ) {
            continue;
        }

        // If we are waiting for a specific transaction, keep reading until we get it
        if ( $expected_tid !== null ) {
            $tid = (int)(string)$xml->attributes()->transaction_id;
            if ( $tid !== $expected_tid ) {
                continue;
            }
        }

        return $xml;
    }
}

// -------------------------------------------------------------------------
// Debugger state
// -------------------------------------------------------------------------

class XdDebugSession {
    public $conn;
    public int $tid = 1;
    public string $current_file = '';
    public int    $current_line = 0;
    public array  $breakpoints  = [];   // id => [file, line]

    public function __construct( $conn ) {
        $this->conn = $conn;
    }

    public function send( string $command ): SimpleXMLElement|false {
        $tid = $this->tid++;
        xd_send( $this->conn, $command, $tid );
        $xml = xd_read_response( $this->conn, $tid );
        return $xml ?? false;
    }
}

// -------------------------------------------------------------------------
// Command implementations
// -------------------------------------------------------------------------

function xd_show_location( XdDebugSession $s ): void {
    if ( $s->current_file === '' ) {
        return;
    }
    $short = basename( $s->current_file );
    echo xd_bold( "=> " ) . xd_cyan( $short ) . xd_bold( ":{$s->current_line}" ) . "\n";
}

function xd_list_source( XdDebugSession $s, int $center = 0, int $context = 5 ): void {
    $file = $s->current_file;
    if ( ! $file || ! file_exists( $file ) ) {
        echo xd_red( "Source file not available: {$file}\n" );
        return;
    }

    if ( $center <= 0 ) {
        $center = $s->current_line;
    }

    $lines  = file( $file );
    $total  = count( $lines );
    $start  = max( 1, $center - $context );
    $end    = min( $total, $center + $context );

    for ( $i = $start; $i <= $end; $i++ ) {
        $marker = ( $i === $s->current_line ) ? xd_yellow( '=>' ) : '  ';
        $lnum   = str_pad( (string)$i, 4, ' ', STR_PAD_LEFT );
        $code   = rtrim( $lines[ $i - 1 ] );
        echo "{$marker} " . xd_dim( $lnum ) . "  {$code}\n";
    }
}

function xd_update_position( XdDebugSession $s, SimpleXMLElement $xml ): void {
    // Position may live in the top-level response attributes …
    $attrs = $xml->attributes();
    $file  = (string)( $attrs->filename ?? '' );
    $line  = (int)(string)( $attrs->lineno ?? 0 );

    // … or in the <xdebug:message> child element (Xdebug 3.x)
    $ns_uri  = 'https://xdebug.org/dbgp/xdebug';
    $message = $xml->children( $ns_uri )->message ?? null;
    if ( $message ) {
        $ma   = $message->attributes();
        $file = (string)( $ma->filename ?? $file );
        $line = (int)(string)( $ma->lineno  ?? $line );
    }

    if ( $file !== '' ) {
        $s->current_file = preg_replace( '#^file://#', '', $file );
    }
    if ( $line > 0 ) {
        $s->current_line = $line;
    }
}

function xd_check_status( XdDebugSession $s, SimpleXMLElement $xml ): string {
    $attrs  = $xml->attributes();
    $status = (string)( $attrs->status ?? '' );
    $reason = (string)( $attrs->reason ?? '' );

    if ( $status === 'stopping' || $status === 'stopped' ) {
        echo xd_green( "\nScript finished.\n" );
        return 'done';
    }

    if ( $status === 'break' ) {
        xd_update_position( $s, $xml );
        xd_show_location( $s );
        xd_list_source( $s );
    }

    return $status;
}

// ----- Individual command handlers ----------------------------------------

function cmd_run( XdDebugSession $s, string $_args ): string {
    $xml = $s->send( 'run' );
    if ( $xml === false ) { return 'done'; }
    return xd_check_status( $s, $xml );
}

function cmd_step_into( XdDebugSession $s, string $_args ): string {
    $xml = $s->send( 'step_into' );
    if ( $xml === false ) { return 'done'; }
    return xd_check_status( $s, $xml );
}

function cmd_step_over( XdDebugSession $s, string $_args ): string {
    $xml = $s->send( 'step_over' );
    if ( $xml === false ) { return 'done'; }
    return xd_check_status( $s, $xml );
}

function cmd_step_out( XdDebugSession $s, string $_args ): string {
    $xml = $s->send( 'step_out' );
    if ( $xml === false ) { return 'done'; }
    return xd_check_status( $s, $xml );
}

function cmd_breakpoint( XdDebugSession $s, string $args ): string {
    $parts = preg_split( '/\s+/', trim( $args ) );

    $file = null;
    $line = null;

    if ( count( $parts ) === 1 && is_numeric( $parts[0] ) ) {
        $file = $s->current_file;
        $line = (int)$parts[0];
    } elseif ( count( $parts ) >= 2 ) {
        $file = $parts[0];
        $line = (int)$parts[1];
        // resolve relative paths
        if ( ! file_exists( $file ) && file_exists( dirname( $s->current_file ) . '/' . $file ) ) {
            $file = dirname( $s->current_file ) . '/' . $file;
        }
        $file = realpath( $file ) ?: $file;
    } else {
        echo xd_red( "Usage: b [file] <line>\n" );
        return 'break';
    }

    $uri = 'file://' . $file;
    $xml = $s->send( "breakpoint_set -t line -f {$uri} -n {$line}" );
    if ( $xml === false ) { return 'break'; }

    $id = (int)(string)( $xml->attributes()->id ?? 0 );
    $s->breakpoints[ $id ] = [ 'file' => $file, 'line' => $line ];
    echo xd_green( "Breakpoint #{$id} set at " ) . xd_cyan( basename( $file ) ) . xd_green( ":{$line}\n" );

    return 'break';
}

function cmd_breakpoint_list( XdDebugSession $s, string $_args ): string {
    if ( empty( $s->breakpoints ) ) {
        echo "No breakpoints set.\n";
        return 'break';
    }
    foreach ( $s->breakpoints as $id => $bp ) {
        echo xd_cyan( "#{$id}" ) . "  " . basename( $bp['file'] ) . ":{$bp['line']}\n";
    }
    return 'break';
}

function cmd_breakpoint_delete( XdDebugSession $s, string $args ): string {
    $id = (int)trim( $args );
    $xml = $s->send( "breakpoint_remove -d {$id}" );
    if ( $xml === false ) { return 'break'; }

    unset( $s->breakpoints[ $id ] );
    echo xd_yellow( "Breakpoint #{$id} removed.\n" );
    return 'break';
}

function cmd_eval( XdDebugSession $s, string $args ): string {
    if ( trim( $args ) === '' ) {
        echo xd_red( "Usage: p <expression>\n" );
        return 'break';
    }
    $encoded = base64_encode( $args );
    $xml = $s->send( "eval -- {$encoded}" );
    if ( $xml === false ) { return 'break'; }

    $error = $xml->error ?? null;
    if ( $error ) {
        $msg = (string)( $error->message ?? 'unknown error' );
        echo xd_red( "Error: {$msg}\n" );
        return 'break';
    }

    $prop = $xml->property ?? null;
    if ( $prop ) {
        $has_name = ( (string)( $prop->attributes()->name ?? '' ) !== '' );
        echo xd_format_property( $prop, 0, $has_name ) . "\n";
    }
    return 'break';
}

function cmd_variables( XdDebugSession $s, string $_args ): string {
    $xml = $s->send( 'context_get' );
    if ( $xml === false ) { return 'break'; }

    foreach ( $xml->property as $prop ) {
        echo xd_format_property( $prop ) . "\n";
    }
    return 'break';
}

function cmd_backtrace( XdDebugSession $s, string $_args ): string {
    $xml = $s->send( 'stack_get' );
    if ( $xml === false ) { return 'break'; }

    foreach ( $xml->stack as $frame ) {
        $a     = $frame->attributes();
        $level = (int)(string)( $a->level ?? 0 );
        $where = (string)( $a->where ?? '{main}' );
        $file  = basename( preg_replace( '#^file://#', '', (string)( $a->filename ?? '' ) ) );
        $line  = (string)( $a->lineno ?? '?' );
        $pfx   = $level === 0 ? xd_yellow( '> ' ) : '  ';
        echo "{$pfx}" . xd_cyan( "#{$level}" ) . "  {$where}  " . xd_dim( "{$file}:{$line}" ) . "\n";
    }
    return 'break';
}

function cmd_list( XdDebugSession $s, string $args ): string {
    $parts   = preg_split( '/\s+/', trim( $args ) );
    $center  = ( $parts[0] !== '' && is_numeric( $parts[0] ) ) ? (int)$parts[0] : 0;
    $context = isset( $parts[1] ) && is_numeric( $parts[1] ) ? (int)$parts[1] : 5;
    xd_list_source( $s, $center, $context );
    return 'break';
}

function cmd_help(): string {
    echo <<<'HELP'
Commands:
  r, run              Continue until next breakpoint or end of script
  s, step             Step into next statement
  n, next             Step over next statement
  o, out              Step out of current function
  b [file] <line>     Set a breakpoint
  bl                  List all breakpoints
  bd <id>             Delete a breakpoint by ID
  p <expr>            Print / evaluate a PHP expression
  v                   Show local variables
  bt                  Show call stack
  l [line] [count]    List source (default ±5 lines around current position)
  h, help, ?          Show this help
  q, quit             Quit the debugger

HELP;
    return 'break';
}

// -------------------------------------------------------------------------
// Property formatter
// -------------------------------------------------------------------------

function xd_format_property( SimpleXMLElement $prop, int $indent = 0, bool $show_name = true ): string {
    $a        = $prop->attributes();
    $name     = (string)( $a->name ?? $a->fullname ?? '' );
    $type     = (string)( $a->type ?? 'unknown' );
    $enc      = (string)( $a->encoding ?? '' );
    $children = (int)(string)( $a->numchildren ?? 0 );

    $pad = str_repeat( '  ', $indent );

    // Decode value
    $raw = (string)$prop;
    if ( $enc === 'base64' ) {
        $raw = base64_decode( $raw );
    }

    $value_str = '';
    if ( $type === 'uninitialized' ) {
        $value_str = xd_dim( 'uninitialized' );
    } elseif ( $type === 'null' ) {
        $value_str = xd_dim( 'null' );
    } elseif ( $type === 'bool' ) {
        $value_str = xd_yellow( $raw === '1' ? 'true' : 'false' );
    } elseif ( $type === 'int' || $type === 'float' ) {
        $value_str = xd_yellow( $raw );
    } elseif ( $type === 'string' ) {
        $display = ( strlen( $raw ) > 200 ) ? substr( $raw, 0, 200 ) . '…' : $raw;
        $value_str = xd_green( '"' . addcslashes( $display, '"\\' ) . '"' );
    } elseif ( $type === 'array' || $type === 'object' ) {
        $class = (string)( $a->classname ?? '' );
        $label = $type === 'object' ? "object({$class})" : "array";
        if ( $children > 0 ) {
            $lines = [ xd_cyan( $label ) . "({$children}) {" ];
            foreach ( $prop->property as $child ) {
                $lines[] = xd_format_property( $child, $indent + 1, true );
            }
            $lines[] = $pad . '}';
            $prefix  = ( $show_name && $name !== '' ) ? "{$pad}" . xd_bold( $name ) . ' = ' : $pad;
            return $prefix . implode( "\n", $lines );
        }
        $value_str = xd_cyan( $label ) . '(0) {}';
    } else {
        $value_str = $raw;
    }

    $prefix = ( $show_name && $name !== '' ) ? "{$pad}" . xd_bold( $name ) . ' = ' : $pad;
    return $prefix . $value_str;
}
// -------------------------------------------------------------------------
// Main: start the debug server and the child PHP process
// -------------------------------------------------------------------------

function xd_find_free_port(): int {
    $socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
    if ( ! $socket ) {
        return 9003;
    }
    $name = stream_socket_get_name( $socket, false );
    fclose( $socket );
    return (int)explode( ':', $name )[1];
}

$port = $opts['port'];

// Bind the DBGp listen socket
$server = @stream_socket_server( "tcp://127.0.0.1:{$port}", $errno, $errstr );
if ( ! $server ) {
    // Try a free port if the requested one is busy
    $port   = xd_find_free_port();
    $server = @stream_socket_server( "tcp://127.0.0.1:{$port}", $errno, $errstr );
    if ( ! $server ) {
        fwrite( STDERR, "Could not bind DBGp server: {$errstr}\n" );
        exit( 1 );
    }
}

// Build the PHP command
$script_args = implode( ' ', array_map( 'escapeshellarg', $opts['args'] ) );
$extra_ini   = getenv( 'TEST_PHP_ARGS' ) ?: '';

$cmd = sprintf(
    '%s %s'
    . ' -d xdebug.mode=debug'
    . ' -d xdebug.start_with_request=yes'
    . ' -d xdebug.client_host=127.0.0.1'
    . ' -d xdebug.client_port=%d'
    . ' -d xdebug.log_level=0'
    . ' %s %s',
    escapeshellarg( $opts['php'] ),
    $extra_ini,
    $port,
    escapeshellarg( $opts['file'] ),
    $script_args
);

echo xd_bold( "Xdebug Terminal Debugger\n" );
echo "Debugging: " . xd_cyan( basename( $opts['file'] ) ) . "\n";
echo "Use " . xd_bold( "'h'" ) . " for help, " . xd_bold( "'q'" ) . " to quit.\n\n";

// Launch PHP in the background
$descriptorspec = [
    0 => [ 'pipe', 'r' ],
    1 => [ 'pipe', 'w' ],
    2 => [ 'pipe', 'w' ],
];
$process = proc_open( $cmd, $descriptorspec, $pipes );
if ( ! is_resource( $process ) ) {
    fwrite( STDERR, "Failed to launch PHP process.\n" );
    exit( 1 );
}

// Wait for Xdebug to connect
stream_set_timeout( $server, 10 );
$conn = stream_socket_accept( $server, 10, $peer );
if ( $conn === false ) {
    fwrite( STDERR, "Timeout: Xdebug did not connect.\n" );
    fwrite( STDERR, "Make sure Xdebug is installed and enabled for PHP binary: {$opts['php']}\n" );
    fwrite( STDERR, stream_get_contents( $pipes[2] ) . "\n" );
    proc_close( $process );
    exit( 1 );
}

fclose( $server );

$session = new XdDebugSession( $conn );

// Read the init packet
$init_xml = xd_read_response( $conn );
if ( $init_xml === null ) {
    fwrite( STDERR, "Did not receive DBGp init packet.\n" );
    exit( 1 );
}

// Enable stdout/stderr forwarding from the debuggee
$session->send( 'stdout -c 1' );
$session->send( 'stderr -c 1' );

// Perform the initial step_into so we stop at the first line
$xml = $session->send( 'step_into' );
if ( $xml !== false ) {
    xd_check_status( $session, $xml );
}

// -------------------------------------------------------------------------
// Interactive REPL
// -------------------------------------------------------------------------

$status = 'break';

while ( $status !== 'done' ) {
    // Read user input
    $prompt = xd_bold( '(xdebug) ' );
    if ( xd_colors_enabled() ) {
        // Use readline if available for history support
        if ( function_exists( 'readline' ) ) {
            $line = readline( "\033[1m(xdebug) \033[0m" );
            if ( $line === false ) {
                // EOF / Ctrl-D
                break;
            }
            if ( trim( $line ) !== '' ) {
                readline_add_history( $line );
            }
        } else {
            echo $prompt;
            $line = fgets( STDIN );
            if ( $line === false ) {
                break;
            }
        }
    } else {
        echo $prompt;
        $line = fgets( STDIN );
        if ( $line === false ) {
            break;
        }
    }

    $line  = trim( (string)$line );
    $parts = preg_split( '/\s+/', $line, 2 );
    $cmd2  = strtolower( $parts[0] );
    $args  = $parts[1] ?? '';

    switch ( $cmd2 ) {
        case 'r':
        case 'run':
        case 'c':
        case 'continue':
            $status = cmd_run( $session, $args );
            break;

        case 's':
        case 'step':
        case 'step_into':
        case 'si':
            $status = cmd_step_into( $session, $args );
            break;

        case 'n':
        case 'next':
        case 'step_over':
        case 'so':
            $status = cmd_step_over( $session, $args );
            break;

        case 'o':
        case 'out':
        case 'step_out':
            $status = cmd_step_out( $session, $args );
            break;

        case 'b':
        case 'break':
        case 'bp':
            $status = cmd_breakpoint( $session, $args );
            break;

        case 'bl':
        case 'breakpoints':
            $status = cmd_breakpoint_list( $session, $args );
            break;

        case 'bd':
        case 'breakpoint_delete':
            $status = cmd_breakpoint_delete( $session, $args );
            break;

        case 'p':
        case 'print':
        case 'eval':
        case 'e':
            $status = cmd_eval( $session, $args );
            break;

        case 'v':
        case 'vars':
        case 'variables':
            $status = cmd_variables( $session, $args );
            break;

        case 'bt':
        case 'backtrace':
        case 'stack':
            $status = cmd_backtrace( $session, $args );
            break;

        case 'l':
        case 'list':
        case 'src':
            $status = cmd_list( $session, $args );
            break;

        case 'h':
        case 'help':
        case '?':
            $status = cmd_help();
            break;

        case 'q':
        case 'quit':
        case 'exit':
            echo "Quitting.\n";
            $session->send( 'stop' );
            $status = 'done';
            break;

        case '':
            // Repeat last action — step_over by default
            $status = cmd_step_over( $session, '' );
            break;

        default:
            // If user typed something that looks like PHP, try to eval it
            if ( strpbrk( $cmd2, '$(' ) !== false || strlen( $line ) > 2 ) {
                $status = cmd_eval( $session, $line );
            } else {
                echo xd_red( "Unknown command: '{$cmd2}'. Type 'h' for help.\n" );
            }
            break;
    }
}

// -------------------------------------------------------------------------
// Clean up
// -------------------------------------------------------------------------

@fclose( $conn );
@fclose( $pipes[0] );

// Print any remaining stdout/stderr from the child
$out = stream_get_contents( $pipes[1] );
$err = stream_get_contents( $pipes[2] );
@fclose( $pipes[1] );
@fclose( $pipes[2] );

if ( trim( $out ) !== '' ) {
    echo "\n" . xd_dim( "--- script output ---\n" ) . $out;
}
if ( trim( $err ) !== '' ) {
    echo xd_dim( "--- script stderr ---\n" ) . $err;
}

proc_close( $process );
