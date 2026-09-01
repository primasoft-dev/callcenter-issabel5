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
  $Id: DialerProcess.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

/* Material TLS por omisión del listener ECCP. Lo instala eccp-cert.sh, que es
 * invocado por los scripts de instalación del módulo. */
/* Default TLS material of the ECCP listener. It is installed by eccp-cert.sh,
 * which is invoked by the module installation scripts. */
define('ECCP_TLS_CERT', '/etc/issabel/dialer/eccp.pem');
define('ECCP_TLS_KEY',  '/etc/issabel/dialer/eccp.key');

class ECCPProcess extends TuberiaProcess
{
    private $DEBUG = FALSE; // VERDADERO si se activa la depuración
                            // TRUE if debugging is enabled

    private $_log;      // Log abierto por framework de demonio
                        // Log opened by daemon framework

    /* Si se pone a VERDADERO, el programa intenta finalizar y no deben
     * aceptarse conexiones nuevas. Todas las conexiones existentes serán
     * desconectadas. */
    /* If set to TRUE, the program attempts to finish and no new
     * connections should be accepted. All existing connections will be
     * disconnected. */
    private $_finalizandoPrograma = FALSE;

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
    	$this->_log = $oMainLog;

        /* El puerto ECCP siempre va cifrado con TLS. El cliente no verifica el
         * certificado (sólo cifrado, sin autenticación del servidor), así que
         * el dialer sigue siendo alcanzable por localhost, por nombre o por IP
         * sin depender de DNS ni de los SAN del certificado. */
        /* The ECCP port is always TLS encrypted. The client does not verify the
         * certificate (encryption only, no server authentication), so the dialer
         * remains reachable by localhost, by name or by IP without depending on
         * DNS or on the certificate SANs. */
        $sCertTLS = ECCP_TLS_CERT;
        $sClaveTLS = ECCP_TLS_KEY;
        if (isset($infoConfig['eccp']['tls_cert'])) $sCertTLS = $infoConfig['eccp']['tls_cert'];
        if (isset($infoConfig['eccp']['tls_key']))  $sClaveTLS = $infoConfig['eccp']['tls_key'];

        /* Se falla en cerrado: sin certificado utilizable no se levanta el
         * listener, en lugar de exponer ECCP en texto claro. */
        /* Fail closed: without usable certificate material the listener is not
         * started, instead of exposing ECCP in plain text. */
        $sPistaEs = ' - ejecute /opt/issabel/dialer/eccp-cert.sh install';
        $sPistaEn = ' - run /opt/issabel/dialer/eccp-cert.sh install';
        if (!is_readable($sCertTLS) || !is_readable($sClaveTLS)) {
            $this->_log->output(
                "FATAL: no se puede leer el certificado TLS de ECCP ($sCertTLS / $sClaveTLS)". $sPistaEs.
                " | EN: FATAL: cannot read the ECCP TLS certificate ($sCertTLS / $sClaveTLS)". $sPistaEn);
            return FALSE;
        }

        /* No basta con que los ficheros existan: un certificado ilegible para
         * OpenSSL, o una clave que no le corresponde, dejarían el puerto
         * abierto mientras fallan todas las negociaciones, que es mucho más
         * difícil de diagnosticar que no arrancar. */
        /* It is not enough for the files to exist: a certificate OpenSSL
         * cannot parse, or a key that does not belong to it, would leave the
         * port open while every negotiation fails, which is far harder to
         * diagnose than not starting at all. */
        $rCertTLS = @openssl_x509_read(file_get_contents($sCertTLS));
        if ($rCertTLS === FALSE) {
            $this->_log->output(
                "FATAL: el certificado TLS de ECCP no es válido ($sCertTLS)". $sPistaEs.
                " | EN: FATAL: the ECCP TLS certificate is not valid ($sCertTLS)". $sPistaEn);
            return FALSE;
        }
        if (@openssl_pkey_get_private(file_get_contents($sClaveTLS)) === FALSE) {
            $this->_log->output(
                "FATAL: la clave privada TLS de ECCP no es válida ($sClaveTLS)". $sPistaEs.
                " | EN: FATAL: the ECCP TLS private key is not valid ($sClaveTLS)". $sPistaEn);
            return FALSE;
        }
        if (!@openssl_x509_check_private_key($rCertTLS, file_get_contents($sClaveTLS))) {
            $this->_log->output(
                "FATAL: la clave privada TLS de ECCP no corresponde al certificado".
                " ($sClaveTLS / $sCertTLS)".$sPistaEs.
                " | EN: FATAL: the ECCP TLS private key does not match the certificate".
                " ($sClaveTLS / $sCertTLS)".$sPistaEn);
            return FALSE;
        }

        /* Un certificado vencido no impide cifrar, porque el cliente no lo
         * verifica, pero suele indicar una renovación fallida. */
        /* An expired certificate does not prevent encryption, because the
         * client does not verify it, but it usually signals a failed renewal. */
        $aInfoCert = @openssl_x509_parse($rCertTLS);
        if (is_array($aInfoCert) && isset($aInfoCert['validTo_time_t'])
            && $aInfoCert['validTo_time_t'] < time()) {
            $this->_log->output(
                "WARN: el certificado TLS de ECCP está vencido ($sCertTLS)".
                ' - se sigue cifrando, pero conviene renovarlo'.
                " | EN: WARN: the ECCP TLS certificate is expired ($sCertTLS)".
                ' - traffic is still encrypted, but it should be renewed');
        }

        $rContextoSSL = stream_context_create(array('ssl' => array(
            'local_cert'            =>  $sCertTLS,
            'local_pk'              =>  $sClaveTLS,
            'verify_peer'           =>  FALSE,
            'verify_peer_name'      =>  FALSE,
            'allow_self_signed'     =>  TRUE,
            'disable_compression'   =>  TRUE,
            'ciphers'               =>  'HIGH:!aNULL:!eNULL:!MD5:!RC4:!3DES:!EXPORT',
        )));

        $this->_multiplex = new ECCPServer('tcp://0.0.0.0:20005', $this->_log,
            $this->_tuberia, $rContextoSSL);
        $this->_tuberia->registrarMultiplexHijo($this->_multiplex);
        $this->_tuberia->setLog($this->_log);

        // Registro de manejadores de eventos
        // Registration of event handlers
        foreach (array('actualizarConfig', 'emitirEventos',) as $k)
            $this->_tuberia->registrarManejador('SQLWorkerProcess', $k, array($this, "msg_$k"));
        foreach (array('recordingMute', 'recordingUnmute', 'emitirEventos') as $k)
            $this->_tuberia->registrarManejador('AMIEventProcess', $k, array($this, "msg_$k"));
        foreach (array('eccpresponse') as $k)
            $this->_tuberia->registrarManejador('*', $k, array($this, "msg_$k"));

        // Registro de manejadores de eventos desde HubProcess
        // Registration of event handlers from HubProcess
        $this->_tuberia->registrarManejador('HubProcess', 'finalizando', array($this, "msg_finalizando"));

        // Se ha tenido éxito si se están escuchando conexiones
        // Success if connections are being listened to
        return $this->_multiplex->escuchaActiva();
    }

    public function procedimientoDemonio()
    {
        // Rutear todos los mensajes pendientes entre tareas y agentes
        // Route all pending messages between tasks and agents
        if ($this->_multiplex->procesarPaquetes())
            $this->_multiplex->procesarActividad(0);
        else $this->_multiplex->procesarActividad(1);

    	return TRUE;
    }

    public function limpiezaDemonio($signum)
    {
        // Mandar a cerrar todas las conexiones activas
        // Order to close all active connections
        $this->_multiplex->finalizarServidor();
    }

    /**************************************************************************/

    public function msg_emitirEventos($sFuente, $sDestino, $sNombreMensaje,
        $iTimestamp, $datos)
    {
        list($eventos) = $datos;

        $this->_lanzarEventos($eventos);
    }

    public function msg_actualizarConfig($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido | EN: received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_actualizarConfig'), $datos);
    }

    public function msg_finalizando($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output('INFO: recibido mensaje de finalización, se desconectan conexiones... | EN: received termination message, disconnecting connections...');
        $this->_finalizandoPrograma = TRUE;
        $this->_multiplex->finalizarConexionesECCP();
        $this->_tuberia->msg_HubProcess_finalizacionTerminada();
    }

    public function msg_recordingMute($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        list($sAgente, $sTipoLlamada, $idCampaign, $idLlamada) = $datos;

        $this->_multiplex->notificarEvento_RecordingMute($sAgente, $sTipoLlamada, $idCampaign, $idLlamada);
    }

    public function msg_recordingUnmute($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        list($sAgente, $sTipoLlamada, $idCampaign, $idLlamada) = $datos;

        $this->_multiplex->notificarEvento_RecordingUnmute($sAgente, $sTipoLlamada, $idCampaign, $idLlamada);
    }

    public function msg_eccpresponse($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }

        list($sKey, $s, $nuevos_valores, $eventos) = $datos;

        if (!is_null($eventos)) $this->_lanzarEventos($eventos);

        $oConn = $this->_multiplex->getConn($sKey);
        if (is_null($oConn)) {
            $this->_log->output("ERR: ".__METHOD__." conexión ECCP $sKey ya no está presente, no se puede entregar respuesta ECCP. | EN: ECCP connection $sKey no longer present, cannot deliver ECCP response.");
            return;
        }
        $oConn->do_eccpresponse($s, $nuevos_valores);
    }

    private function _lanzarEventos(&$eventos)
    {
        foreach ($eventos as $ev) {
            if (!is_null($ev)) {
                call_user_func_array(
                    array(
                        $this->_multiplex,
                        'notificarEvento_'.$ev[0]),
                    $ev[1]);
            }
        }
    }

    private function _actualizarConfig($k, $v)
    {
        switch ($k) {
        case 'dialer_debug':
            $this->_log->output('INFO: actualizando DEBUG... | EN: updating DEBUG...');
            $this->DEBUG = $v;
            break;
        default:
            $this->_log->output('WARN: '.__METHOD__.': se ignora clave de config no implementada: '.$k.' | EN: unimplemented config key ignored: '.$k);
            break;
        }
    }
}
?>