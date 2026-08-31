<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  Encoding: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 1.2-2                                               |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: MultiplexServer.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

class MultiplexServer
{
    protected $_oLog;        // Objeto log para reportar problemas
                            // Log object to report problems
    protected $_hEscucha;    // Socket de escucha para nuevas conexiones
                            // Listening socket for new connections
    private $_conexiones;    // Lista de conexiones atendidas con clientes
                            // List of connections attended with clients
    private $_uniqueid;

    // Lista de objetos escucha, de tipos variados
    // List of listener objects, of various types
    protected $_listaConn = array();

    /* Contexto SSL del socket de escucha, o NULL si las conexiones son en
     * texto claro. Si no es NULL, cada conexión aceptada debe completar una
     * negociación TLS antes de que se lea dato alguno de ella. */
    /* SSL context of the listening socket, or NULL if connections are in
     * plain text. If not NULL, every accepted connection must complete a TLS
     * negotiation before any data is read from it. */
    private $_rContextoSSL = NULL;

    // Segundos que se concede a una conexión para completar la negociación TLS
    // Seconds granted to a connection to complete the TLS negotiation
    const TIMEOUT_HANDSHAKE_TLS = 15;

    /**
     * Constructor del objeto. Se esperan los siguientes parámetros:
     * Object constructor. The following parameters are expected:
     * @param    string    $sUrlSocket        Especificación del socket de escucha
     *                                  Listening socket specification
     * @param    object    $oLog            Referencia a objeto de log
     *                              Reference to log object
     *
     * El constructor abre el socket de escucha (p.ej. tcp://127.0.0.1:20005
     * o unix:///tmp/dialer.sock) y desactiva el bloqueo para poder usar
     * The constructor opens the listening socket (e.g. tcp://127.0.0.1:20005
     * or unix:///tmp/dialer.sock) and disables blocking to be able to use
     * stream_select() sobre el socket.
     * stream_select() on the socket.
     */
    function __construct($sUrlSocket, &$oLog, $rContextoSSL = NULL)
    {
        $this->_oLog =& $oLog;
        $this->_conexiones = array();
        $this->_uniqueid = 0;
        $this->_rContextoSSL = $rContextoSSL;
        $errno = $errstr = NULL;
        $this->_hEscucha = FALSE;
        if (!is_null($sUrlSocket)) {
            if (is_null($rContextoSSL)) {
                $this->_hEscucha = stream_socket_server($sUrlSocket, $errno, $errstr);
            } else {
                /* Se escucha en tcp:// aun para TLS: la negociación se maneja
                 * manualmente tras aceptar, para que stream_socket_accept()
                 * nunca bloquee el único hilo del servidor. */
                /* Listening on tcp:// even for TLS: the negotiation is handled
                 * manually after accepting, so that stream_socket_accept()
                 * never blocks the server's single thread. */
                $this->_hEscucha = stream_socket_server($sUrlSocket, $errno, $errstr,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $rContextoSSL);
            }
            if (!$this->_hEscucha) {
                $this->_oLog->output("ERR: no se puede iniciar socket de escucha $sUrlSocket: ($errno) $errstr");
            } else {
                // No bloquearse en escucha de conexiones
                // Do not block on listening for connections
                stream_set_blocking($this->_hEscucha, 0);
                $this->_oLog->output("INFO: escuchando peticiones en $sUrlSocket ...");
            }
        }
    }

    /**
     * Función que verifica si la escucha está activa.
     * Function that verifies if listening is active.
     *
     * @return    bool    VERDADERO si escucha está activa, FALSO si no.
     *                      TRUE if listening is active, FALSE otherwise.
     */
    function escuchaActiva()
    {
        return ($this->_hEscucha !== FALSE);
    }

    /**
     * Procedimiento que revisa los sockets para llenar los búferes de lectura
     * y vaciar los búferes de escritura según sea necesario. También se
     * verifica si hay nuevas conexiones para preparar.
     * Procedure that checks sockets to fill read buffers
     * and empty write buffers as needed. It also verifies
     * if there are new connections to prepare.
     *
     * @return    VERDADERO si alguna conexión tuvo actividad
     *              TRUE if any connection had activity
     */
    function procesarActividad($tv_sec = 1, $tv_usec = 0)
    {
        $bNuevosDatos = $this->_ejecutarIO($tv_sec, $tv_usec, TRUE);

        if ($bNuevosDatos) {
            foreach ($this->_conexiones as $sKey => &$conexion) {
                if ($conexion['nuevos_datos_leer']) {
                    $this->_procesarNuevosDatos($sKey);
                    $conexion['nuevos_datos_leer'] = FALSE;
                    $this->_ejecutarIO(0, 0);
                }
            }
        }

        return $bNuevosDatos;
    }

    private function _ejecutarIO($tv_sec, $tv_usec, $listen = FALSE)
    {
        $bNuevosDatos = FALSE;
        $listoLeer = array();
        $listoEscribir = array();
        $listoErr = NULL;

        // Se recogen bandera de datos no procesados pendientes
        // Collect flag of pending unprocessed data
        foreach ($this->_conexiones as $sKey => &$conexion) {
            if ($conexion['nuevos_datos_leer']) $bNuevosDatos = TRUE;
        }

        // Si ya hay datos pendientes, no hay que esperar en select
        // If there is already pending data, no need to wait in select
        if ($bNuevosDatos) {
            $tv_sec = 0;
            $tv_usec = 0;
        }

        // Recolectar todos los descriptores que se monitorean
        // Collect all descriptors that are monitored
        if ($listen && $this->_hEscucha)
            $listoLeer[] = $this->_hEscucha;        // Escucha de nuevas conexiones
                                                    // Listening for new connections
        foreach ($this->_conexiones as &$conexion) {
            if (!$conexion['exit_request']) $listoLeer[] = $conexion['socket'];
            if (strlen($conexion['pendiente_escribir']) > 0) {
                $listoEscribir[] = $conexion['socket'];
            }
        }
        $iNumCambio = (count($listoLeer) + count($listoEscribir) > 0)
            ? @stream_select($listoLeer, $listoEscribir, $listoErr, $tv_sec, $tv_usec)
            : 0;
        if ($iNumCambio === false) {
            // Interrupción, tal vez una señal
            // Interruption, perhaps a signal
            $this->_oLog->output("INFO: select() finaliza con fallo - señal pendiente?");
        } elseif ($iNumCambio > 0 || count($listoLeer) > 0 || count($listoEscribir) > 0) {
            if (in_array($this->_hEscucha, $listoLeer)) {
                // Entra una conexión nueva
                // A new connection comes in
                $this->_procesarConexionNueva();
                $bNuevosDatos = TRUE;
            }
            foreach ($this->_conexiones as $sKey => &$conexion) {
                if (in_array($conexion['socket'], $listoEscribir)) {
                    // Escribir lo más que se puede de los datos pendientes por mostrar
                    // Write as much as possible of pending data to display
                    $iBytesEscritos = @fwrite($conexion['socket'], $conexion['pendiente_escribir']);
                    if ($iBytesEscritos === FALSE) {
                        $this->_oLog->output("ERR: error al escribir datos a ".$conexion['socket']);
                        $this->_cerrarConexion($sKey);
                    } else {
                        $conexion['pendiente_escribir'] = substr($conexion['pendiente_escribir'], $iBytesEscritos);
                        $bNuevosDatos = TRUE;
                    }
                }
                if (in_array($conexion['socket'], $listoLeer)) {
                    if (!empty($conexion['handshake_pendiente'])) {
                        // Todavía se está negociando TLS en esta conexión
                        // TLS is still being negotiated on this connection
                        $this->_continuarHandshakeTLS($sKey);
                    } else {
                        // Leer datos de la conexión lista para leer
                        // Read data from connection ready to read
                        $sNuevaEntrada = @fread($conexion['socket'], 128 * 1024);
                        if ($sNuevaEntrada === FALSE || $sNuevaEntrada === '') {
                            /* Una lectura vacía sólo indica cierre remoto si el
                             * socket está en EOF. Sobre TLS, el socket puede
                             * estar listo para leer teniendo sólo un registro
                             * TLS parcial, que no produce dato alguno en claro;
                             * cerrar ahí desconectaría consolas activas. */
                            /* An empty read only indicates remote closure if the
                             * socket is at EOF. Over TLS, the socket can be ready
                             * to read while holding only a partial TLS record,
                             * which yields no plaintext at all; closing there
                             * would disconnect live consoles. */
                            if (feof($conexion['socket'])) $this->_cerrarConexion($sKey);
                        } else {
                            $conexion['pendiente_leer'] .= $sNuevaEntrada;
                            $conexion['nuevos_datos_leer'] = TRUE;
                        }
                    }
                    $bNuevosDatos = TRUE;
                }
            }

            // Cerrar todas las conexiones que no tienen más datos que mostrar
            // y que han marcado que deben terminarse
            // Close all connections that have no more data to display
            // and have marked that they must be terminated
            foreach ($this->_conexiones as $sKey => &$conexion) {
                if (is_array($conexion) && $conexion['exit_request'] && strlen($conexion['pendiente_escribir']) <= 0) {
                    $this->_cerrarConexion($sKey);
                }
            }

            // Remover todos los elementos seteados a FALSE
            // Remove all elements set to FALSE
            $this->_conexiones = array_filter($this->_conexiones);
        }

        /* Descartar conexiones que abren el socket pero nunca completan la
         * negociación TLS. Va fuera del bloque anterior para que también se
         * apliquen cuando el servidor está totalmente inactivo. */
        /* Discard connections that open the socket but never complete the TLS
         * negotiation. This goes outside the block above so that it also
         * applies when the server is completely idle. */
        if (!is_null($this->_rContextoSSL)) {
            $iAhora = time();
            $bCerradoPorTimeout = FALSE;
            foreach ($this->_conexiones as $sKeyTLS => $conexionTLS) {
                if (is_array($conexionTLS) && !empty($conexionTLS['handshake_pendiente'])
                    && $conexionTLS['handshake_limite'] > 0
                    && $iAhora > $conexionTLS['handshake_limite']) {
                    $this->_oLog->output('WARN: '.__METHOD__.
                        ": negociación TLS sin completar a tiempo, se cierra conexión $sKeyTLS".
                        ' | EN: WARN: '.__METHOD__.
                        ": TLS negotiation not completed in time, closing connection $sKeyTLS");
                    $this->_cerrarConexion($sKeyTLS);
                    $bCerradoPorTimeout = TRUE;
                }
            }
            if ($bCerradoPorTimeout) $this->_conexiones = array_filter($this->_conexiones);
        }

        return $bNuevosDatos;
    }

    /**
     * Procedimiento para agregar un objeto instancia de MultiplexConn, que abre
     * un socket arbitrario y desea estar asociado con tal socket.
     * Procedure to add an instance object of MultiplexConn, which opens
     * an arbitrary socket and wishes to be associated with such socket.
     *
     * @param   object      $oNuevaConn Objeto que hereda de DialerConn
     *                              Object that inherits from DialerConn
     * @param   resource    $hSock      Conexión a un socket TCP o UNIX
     *                              Connection to a TCP or UNIX socket
     *
     * @return void
     */
    function agregarNuevaConexion($oNuevaConn, $hSock)
    {
        if (!is_a($oNuevaConn, 'MultiplexConn')) {
            die(__METHOD__.' - $oNuevaConn no es subclase de MultiplexConn');
        }

        $sKey = $this->agregarConexion($hSock);
        $oNuevaConn->multiplexSrv = $this;
        $oNuevaConn->sKey = $sKey;
        $this->_listaConn[$sKey] = $oNuevaConn;
        $this->_listaConn[$sKey]->procesarInicial();
    }

    /* Enviar los datos recibidos para que sean procesados por la conexión */
    /* Send received data to be processed by the connection */
    private function _procesarNuevosDatos($sKey)
    {
        if (isset($this->_listaConn[$sKey])) {
            $sDatos = $this->obtenerDatosLeidos($sKey);
            $iLongProcesado = $this->_listaConn[$sKey]->parsearPaquetes($sDatos);

            if (!isset($this->_conexiones[$sKey])) return;
            if ($iLongProcesado < 0) return;
            $this->_conexiones[$sKey]['pendiente_leer'] =
                (strlen($this->_conexiones[$sKey]['pendiente_leer']) > $iLongProcesado)
                ? substr($this->_conexiones[$sKey]['pendiente_leer'], $iLongProcesado)
                : '';
        }
    }

    function procesarCierre($sKey)
    {
        if (isset($this->_listaConn[$sKey])) {
            $this->_listaConn[$sKey]->procesarCierre();
            unset($this->_listaConn[$sKey]);
        }
    }

    function procesarPaquetes()
    {
        $bHayProcesados = FALSE;
        foreach ($this->_listaConn as &$oConn) {
            if ($oConn->hayPaquetes()) {
                $bHayProcesados = TRUE;
                $oConn->procesarPaquete();
                $this->_ejecutarIO(0, 0);
            }
        }
        return $bHayProcesados;
    }


    // Procesar una nueva conexión que ingresa al servidor
    // Process a new connection that enters the server
    private function _procesarConexionNueva()
    {
        $hConexion = @stream_socket_accept($this->_hEscucha);
        if ($hConexion === FALSE) return;

        if (is_null($this->_rContextoSSL)) {
            $sKey = $this->agregarConexion($hConexion);
            $this->procesarInicial($sKey);
        } else {
            /* La conexión no se entrega a la subclase hasta que la negociación
             * TLS se complete, para que no se escriba nada en claro. */
            /* The connection is not handed to the subclass until the TLS
             * negotiation completes, so that nothing is written in the clear. */
            $sKey = $this->agregarConexion($hConexion, TRUE);
            $this->_continuarHandshakeTLS($sKey);
        }
    }

    /* Avanzar la negociación TLS de una conexión aceptada. El socket es no
     * bloqueante, así que stream_socket_enable_crypto() puede devolver 0 para
     * indicar que faltan datos; en ese caso se reintenta cuando el socket
     * vuelva a tener datos para leer. */
    /* Advance the TLS negotiation of an accepted connection. The socket is
     * non-blocking, so stream_socket_enable_crypto() may return 0 to indicate
     * that more data is required; in that case it is retried when the socket
     * next has data to read. */
    private function _continuarHandshakeTLS($sKey)
    {
        if (!isset($this->_conexiones[$sKey]) || !is_array($this->_conexiones[$sKey])) return;

        /* Se exige TLS 1.3, cuyas suites son todas AEAD (la negociada es
         * TLS_AES_256_GCM_SHA384). Sólo se admite TLS 1.2 si la instalación de
         * PHP/OpenSSL no conoce TLS 1.3. */
        /* TLS 1.3 is required; all of its suites are AEAD (the negotiated one
         * is TLS_AES_256_GCM_SHA384). TLS 1.2 is only allowed if this
         * PHP/OpenSSL installation does not know about TLS 1.3. */
        $iMetodo = defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')
            ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
            : STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;

        $r = @stream_socket_enable_crypto($this->_conexiones[$sKey]['socket'], TRUE, $iMetodo);
        if ($r === TRUE) {
            // Negociación completa, la conexión ya puede usarse normalmente
            // Negotiation complete, the connection can now be used normally
            $this->_conexiones[$sKey]['handshake_pendiente'] = FALSE;
            $this->_conexiones[$sKey]['handshake_limite'] = 0;
            $this->procesarInicial($sKey);
        } elseif ($r === FALSE) {
            $this->_oLog->output('WARN: '.__METHOD__.
                ": fallo en negociación TLS, se cierra conexión $sKey".
                ' | EN: WARN: '.__METHOD__.": TLS negotiation failed, closing connection $sKey");
            $this->_cerrarConexion($sKey);
        }
        // $r === 0: la negociación requiere más datos, se reintenta luego
        // $r === 0: the negotiation requires more data, retried later
    }

    /**
     * Procedimiento que agrega una conexión socket arbitraria a la lista de los
     * sockets que hay que monitorear para escucha.
     * Procedure that adds an arbitrary socket connection to the list of
     * sockets that need to be monitored for listening.
     *
     * @param mixed $hConexion Conexión socket a agregar a la lista
     *                      Socket connection to add to the list
     *
     * @return Clave a usar para identificar la conexión
     *          Key to use to identify the connection
     */
    protected function agregarConexion($hConexion, $bHandshakeTLS = FALSE)
    {
        $nuevaConn = array(
            'socket'                =>  $hConexion,
            'pendiente_leer'        =>  '',
            'pendiente_escribir'    =>  '',
            'exit_request'          =>  FALSE,
            'nuevos_datos_leer'     =>  FALSE,
            'handshake_pendiente'   =>  $bHandshakeTLS,
            'handshake_limite'      =>  $bHandshakeTLS ? (time() + self::TIMEOUT_HANDSHAKE_TLS) : 0,
        );
        stream_set_blocking($nuevaConn['socket'], 0);

        $sKey = "K_{$this->_uniqueid}";
        $this->_uniqueid++;
        $this->_conexiones[$sKey] =& $nuevaConn;
        return $sKey;
    }

    /**
     * Recuperar los primeros $iMaxBytes del búfer de lectura. Por omisión se
     * devuelve la totalidad del búfer.
     * Retrieve the first $iMaxBytes from the read buffer. By default the
     * entirety of the buffer is returned.
     * @param    string    $sKey        Clave de la conexión pasada a procesarNuevosDatos()
     *                              Key of the connection passed to procesarNuevosDatos()
     * @param    int        $iMaxBytes    Longitud máxima en bytes a devolver (por omisión todo)
     *                              Maximum length in bytes to return (by default all)
     *
     * @return    string    Cadena con los datos del bufer
     *                      String with buffer data
     */
    protected function obtenerDatosLeidos($sKey, $iMaxBytes = 0)
    {
        $iMaxBytes = (int)$iMaxBytes;
        if (!isset($this->_conexiones[$sKey])) return NULL;
        return ($iMaxBytes > 0)
            ? substr($this->_conexiones[$sKey]['pendiente_leer'], 0, $iMaxBytes)
            : $this->_conexiones[$sKey]['pendiente_leer'];
    }

    /**
     * Agregar datos al búfer de escritura pendiente, los cuales serán escritos
     * al cliente durante la siguiente llamada a procesarActividad()
     * Add data to the pending write buffer, which will be written
     * to the client during the next call to procesarActividad()
     * @param    string    $sKey    Clave de la conexión pasada a procesarNuevosDatos()
     *                          Key of the connection passed to procesarNuevosDatos()
     * @param    string    $s        Búfer de datos a agregar a los datos a escribir.
     *                          Data buffer to add to data to write.
     *
     * @return    void
     */
    public function encolarDatosEscribir($sKey, &$s)
    {
        if (!isset($this->_conexiones[$sKey])) return;
        if (!is_string($s)) {
            /* Un FALSE aqui viene tipicamente de un asXML() que fallo. Al
             * concatenarlo se convierte en cadena vacia y la respuesta o el
             * evento desaparecen sin dejar rastro; se reporta en vez de
             * perderse en silencio. */
            /* A FALSE here typically comes from a failed asXML(). Concatenating
             * it turns it into an empty string and the response or event
             * vanishes without a trace; report it instead of losing it
             * silently. */
            $this->_oLog->output('ERR: '.__METHOD__.": datos a escribir para $sKey no son".
                ' una cadena ('.gettype($s).'), no se escribe nada. | EN: ERR: '.__METHOD__.
                ": data to write for $sKey is not a string (".gettype($s).'), nothing written.');
            return;
        }
        $this->_conexiones[$sKey]['pendiente_escribir'] .= $s;
    }

    /**
     * Marcar que el socket indicado debe de cerrarse. Ya no se procesarán más
     * datos de entrada del socket indicado, desde el punto de vista de la
     * aplicación. Todos los datos pendientes por escribir se escribirán antes
     * de cerrar el socket.
     * Mark that the indicated socket must be closed. No more input data
     * will be processed from the indicated socket, from the application's
     * point of view. All pending data to write will be written before
     * closing the socket.
     * @param    string    $sKey    Clave de la conexión pasada a procesarNuevosDatos()
     *                          Key of the connection passed to procesarNuevosDatos()
     *
     * @return    void
     */
    public function marcarCerrado($sKey)
    {
        if (!isset($this->_conexiones[$sKey])) return;
        $this->_conexiones[$sKey]['exit_request'] = TRUE;
    }

    // Procesar realmente una conexión que debe cerrarse
    // Actually process a connection that must be closed
    private function _cerrarConexion($sKey)
    {
        fclose($this->_conexiones[$sKey]['socket']);
        $this->_conexiones[$sKey] = FALSE;  // Será removido por array_map()
                                            // Will be removed by array_map()
        $this->procesarCierre($sKey);
    }

    /**
     * Procedimiento que se debe implementar en la subclase, para manejar la
     * apertura inicial del socket, para poder escribir datos antes de recibir
     * peticiones del cliente. En este punto no hay hay datos leidos del
     * cliente.
     * Procedure that must be implemented in the subclass, to handle the
     * initial opening of the socket, to be able to write data before receiving
     * client requests. At this point there are no data read from the client.
     * @param    string    $sKey    Clave de la conexión recién creada.
     *                          Key of the newly created connection.
     *
     * @return    void
     */
    protected function procesarInicial($sKey) {}

    function finalizarServidor()
    {
        if ($this->_hEscucha !== FALSE) {
            fclose($this->_hEscucha);
            $this->_hEscucha = FALSE;
        }
        foreach ($this->_listaConn as &$oConn) {
            $oConn->finalizarConexion();
        }
        $this->procesarActividad();
    }

    /**
     * Procedimiento que se debe implementar en la subclase, para manejar datos
     * nuevos enviados desde el cliente.
     * Procedure that must be implemented in the subclass, to handle new
     * data sent from the client.
     * @param    string    $sKey    Clave de la conexión con datos nuevos
     *                          Key of the connection with new data
     *
     * @return    void
     */
    //abstract protected function procesarNuevosDatos($sKey);

    /**
     * Procedimiento que se debe implementar en la subclase, para manejar el
     * cierre de la conexión.
     * Procedure that must be implemented in the subclass, to handle the
     * closing of the connection.
     * @param   string  $sKey   Clave de la conexión cerrada
     *                      Key of the closed connection
     *
     * @return  void
     */
    //abstract protected function procesarCierre($sKey);
}
?>