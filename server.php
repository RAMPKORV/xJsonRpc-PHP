<?php
function is_assoc(array $array)
{
    foreach($array as $key => $value)
    {
        if(!is_numeric($key))
            return true;
    }
    return false;
}
class JsonrpcException extends Exception
{
    protected $data;

    public function __construct($code, $message, $data = NULL)
    {
        $this->data = $data;
        parent::__construct($message, $code);
    }

    public function getDict()
    {
        $data = array(
            "code" => $this->getCode(),
            "message" => $this->getMessage()
        );
        if($this->data)
            $data["data"] = $this->data;

        return json_encode($data);
    }
}

/**
 * Invalid JSON was received by the server.
 * An error occurred on the server while parsing the JSON text
 */
class JsonrpcParseError extends JsonrpcException
{
    public function __construct()
    {
        parent::__construct(-32700, "Parse error");
    }
}

/**
 * The JSON sent is not a valid Request object
 */
class JsonrpcInvalidRequestError extends JsonrpcException
{
    public function __construct()
    {
        parent::__construct(-32600, "Invalid request");
    }
}

/**
 * The JSON-RPC call is using an incompatible version
 */
class JsonrpcInvalidVersionError extends JsonrpcException
{
    public function __construct()
    {
        parent::__construct(0, "Incompatible version", array(expectedVersion => "2.0"));
    }
}

/**
 * The method does not exist / is not available.
 */
class JsonrpcMethodNotFoundError extends JsonrpcException
{
    public function __construct()
    {
        parent::__construct(-32601, "Method not found");
    }
}

/**
 * Invalid method parameter(s).
 */
class JsonrpcInvalidParamsError extends JsonrpcException
{
    public function __construct()
    {
        parent::__construct(-32602, "Invalid params");
    }
}

/**
 * Internal JSON-RPC error.
 */
class JsonrpcInternalError extends JsonrpcException
{
    public function __construct()
    {
        parent::__construct(-32603, "Internal error");
    }
}

class JsonrpcApplicationError extends JsonrpcException
{
}

/**
 * @TODO Handle incorrect amount of arguments (Well lack of arguments)
 */
class Jsonrpc20Server
{
    public function handle($data)
    {
        $result = $this->_handle($data);
        return $this->_encode_json($result);
    }

    protected function _handle($data)
    {
        try
        {
            $request = $this->_parse_json($data);
        }
        catch(JsonrpcParseError $e)
        {
            return $this->_create_error_response($e, NULL);
        }

        return $this->_delegate_request($request);
    }

    protected function _parse_json($json)
    {
        $result = json_decode($json, true);
        if($result === NULL)
            throw new JsonrpcParseError();

        return $result;
    }
    protected function _encode_json($result)
    {
        return json_encode($result);
    }

    protected function _create_success_response($result, $reqid)
    {
        return array(
            "jsonrpc" => "2.0",
            "id" => $reqid,
            "result" => $result
        );
    }

    protected function _create_error_response(Exception $error, $reqid)
    {
        $response = array(
            "jsonrpc" => "2.0",
            "id" => $reqid
        );
        if($error instanceof JsonrpcException)
            $response["error"] = $error->getDict();
        else
        {
            print $error;
            $response["error"] = array(
                "code" => 0,
                "message" => $error->getMessage()
            );
        }
        return $response;
    }

    protected function _delegate_request($request)
    {
        if(is_assoc($request))
            return $this->_parse_request($request);
        else
            return $this->_parse_batch_request($request);
    }

    protected function _parse_batch_request($requests)
    {
        $result = array();
        foreach($requests as $request)
            $result[] = $this->_parse_request($request);

        return $result;
    }

    protected function _parse_request($request)
    {
        try
        {
            $this->_validate_request($request);
        }
        catch(JsonrpcException $e)
        {
            return $this->_create_error_response($e, NULL);
        }

        try
        {
            $result = $this->_run_request($request);
            return $this->_create_success_response($result, $request["id"]);
        }
        catch(JsonrpcException $e)
        {
            return $this->_create_error_response($e, $request["id"]);
        }
    }

    protected function _validate_request($request)
    {
        if(!array_key_exists("method", $request)
            || !array_key_exists("id", $request))
        {
            print "InvalidRequest: Missing method and/or id";
            throw new JsonrpcInvalidRequestError();
        }

        if(!array_key_exists("jsonrpc", $request) || $request["jsonrpc"] != "2.0")
            throw new JsonrpcInvalidVersionError();

        if(!is_string($request["method"]))
        {
            print "InvalidRequest: Method is not string but " . gettype($request["method"]);
            throw new JsonrpcInvalidRequestError();
        }

        if(array_key_exists("params", $request) && !is_array($request["params"]))
        {
            print "InvalidRequest: Params is not array but " . gettype($request["params"]);
            throw new JsonrpcInvalidRequestError();
        }

        if(!is_numeric($request["id"]) && !is_string($request["id"]))
        {
            print "InvalidRequest: id isn't string or int but " . gettype($request["id"]);
            throw new JsonrpcInvalidRequestError();
        }

        return true;
    }

    protected function _run_request($request)
    {
        $reqid = $request["id"];
        $method = $request["method"];
        $params = array_key_exists("params", $request) ? $request["params"] : NULL;

        $methodName = "jsonrpc20_" . $method;
        if(!method_exists($this, $methodName))
            throw new JsonrpcMethodNotFoundError();

        if(is_assoc($params))
            $result = $this->$methodName($params);
        else
            $result = call_user_func_array(array($this, $methodName), $params);

        return $result;
    }
}


class StdinJsonrpc20Server extends Jsonrpc20Server
{
    public function handle()
    {
        $data = file_get_contents('php://stdin');
        return parent::handle($data);
    }
}

class WebJsonrpc20Server extends Jsonrpc20Server
{
    public function handle()
    {
        $data = file_get_contents('php://input');
        return parent::handle($data);
    }
}
?>
