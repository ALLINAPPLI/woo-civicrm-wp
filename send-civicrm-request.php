<?php
if (!defined('WPINC')) {
    die;
}

trait WC_CiviCRM_API_Request
{
    /**
     * Test connection to CiviCRM API
     * 
     * This function performs a simple API call to test if the CiviCRM connection is working.
     * Returns an array with status, message, and any additional debug information.
     * 
     * @return array Connection status and details
     */
    public function test_civicrm_connection()
    {
        try {
            // Make sure we have the required credentials
            if (empty($this->civicrm_url)) {
                return [
                    'success' => false,
                    'status' => 'disconnected',
                    'message' => 'CiviCRM URL is not configured'
                ];
            }

            if (empty($this->auth_token)) {
                return [
                    'success' => false,
                    'status' => 'disconnected',
                    'message' => 'CiviCRM API Token is not configured'
                ];
            }

            // Create a simple endpoint for the Contact.get API call
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contact/get';

            // Set up headers
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'X-Civi-Auth: Bearer ' . $this->auth_token,
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json'
            ];

            // Create minimal request params
            $request_data = [
                'params' => json_encode([
                    'select' => ['id'],
                    'limit' => 1,
                    'checkPermissions' => false
                ])
            ];

            // Set context for the API request
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => $headers,
                    'content' => http_build_query($request_data),
                    'timeout' => 15 // Shorter timeout for connection test
                ]
            ]);

            // Try to make the request
            $response_raw = @file_get_contents($endpoint, false, $context);

            // If request failed
            if ($response_raw === false) {
                $error = error_get_last();
                return [
                    'success' => false,
                    'status' => 'disconnected',
                    'message' => 'Could not connect to CiviCRM API: ' . ($error['message'] ?? 'Unknown error'),
                    'http_error' => $http_response_header ?? []
                ];
            }

            // Parse the response
            $response = json_decode($response_raw, true);

            // Check if valid response format
            if (!is_array($response) || !isset($response['values'])) {
                return [
                    'success' => false,
                    'status' => 'disconnected',
                    'message' => 'Invalid response from CiviCRM API',
                    'response' => $response_raw
                ];
            }

            // Connection successful
            return [
                'success' => true,
                'status' => 'connected',
                'message' => 'Successfully connected to CiviCRM API',
                'data' => [
                    'contactCount' => count($response['values']),
                    'civicrm_version' => $response['civi_api_version'] ?? 'Unknown'
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 'disconnected',
                'message' => 'Error connecting to CiviCRM: ' . $e->getMessage(),
                'exception' => [
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ]
            ];
        }
    }

    private function log_api_request($entity, $action, $params)
    {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_success('api_request', [
                'message' => 'Sending CiviCRM API request',
                'entity' => $entity,
                'action' => $action,
                'params' => $params
            ]);
        }
    }

    private function log_api_response($endpoint, $status, $result)
    {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_success('api_response', [
                'message' => 'Received API response',
                'endpoint' => $endpoint,
                'status' => $status,
                'response' => $result,
                'headers' => $http_response_header ?? []
            ]);
        }
    }

    private function log_api_error($endpoint, $entity, $action, $error)
    {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_error('api_error', [
                'message' => 'API request failed',
                'endpoint' => $endpoint,
                'entity' => $entity,
                'action' => $action,
                'error' => $error instanceof Exception ? $error->getMessage() : $error,
                'trace' => $error instanceof Exception ? $error->getTraceAsString() : null
            ]);
        }
    }

    /**
     * Send a request to the CiviCRM API
     *
     * @param string $entity The entity to access (e.g., Contact, Email)
     * @param string $action The action to perform (e.g., get, create)
     * @param array $params The parameters for the request
     * @return array The API response
     * @throws Exception If the API connection fails
     */
    public function send_civicrm_request($entity, $action, $params = [])
    {
        // Validate required parameters
        if (empty($entity) || empty($action)) {
            throw new Exception('Entity and action are required for API requests');
        }

        // Check if user provided a custom endpoint
        if (isset($params['_endpoint']) && !empty($params['_endpoint'])) {
            $endpoint = $params['_endpoint'];
            // Remove the _endpoint param since CiviCRM API doesn't expect it
            unset($params['_endpoint']);
        } else {
            // Construct the CiviCRM API4 endpoint based on the entity and action
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/' . $entity . '/' . $action;
        }

        // Prepare request parameters
        // For 'get' actions, pass parameters directly
        if ($action === 'get') {
            $request_data = [
                'params' => json_encode($params)
            ];
        } else {
            // For other actions, use values key
            
            // Ensure numeric fields are actually numbers
            if ($action === 'create' && isset($params['values'])) {
                // Fields that should be integers
                $int_fields = [
                    'contact_id',
                    'financial_type_id',
                    'payment_instrument_id',
                    'contribution_status_id',
                    'is_test',
                    'is_pay_later',
                    'location_type_id',
                    'phone_type_id',
                    'country_id',
                    'state_province_id',
                    'is_primary',
                ];
                
                // Fields that should be floats
                $float_fields = ['total_amount', 'fee_amount', 'net_amount', 'non_deductible_amount'];
                
                foreach ($int_fields as $field) {
                    if (isset($params['values'][$field]) && is_string($params['values'][$field])) {
                        $params['values'][$field] = (int)$params['values'][$field];
                    }
                }
                
                foreach ($float_fields as $field) {
                    if (isset($params['values'][$field]) && is_string($params['values'][$field])) {
                        $params['values'][$field] = (float)$params['values'][$field];
                    }
                }
            }
            
            $request_data = [
                'params' => json_encode([
                    'values' => $params['values'] ?? [],
                    'checkPermissions' => $params['checkPermissions'] ?? false
                ])
            ];

            if (isset($request_data['params'])) {
                $params_array = json_decode($request_data['params'], true);
                $params_array['values'] = array_filter($params_array['values'], function ($key) {
                    return !str_contains($key, ':');
                }, ARRAY_FILTER_USE_KEY);
                $request_data['params'] = json_encode($params_array);
            }
        }

        // Prepare headers
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Civi-Auth: Bearer ' . $this->auth_token,
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json'
        ];

        // Prepare request context
        $request_context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => http_build_query($request_data)
            ]
        ]);

        // Log request details for debugging
        // error_log('CiviCRM API Request Details:');
        // error_log('Endpoint: ' . $endpoint);
        // error_log('Entity: ' . $entity);
        // error_log('Action: ' . $action);
        // error_log('Params: ' . json_encode($request_data, JSON_PRETTY_PRINT));
        // error_log('Auth Token: ' . substr($this->auth_token, 0, 5) . '...' . substr($this->auth_token, -5));

        // Execute the request
        try {
            $response_raw = @file_get_contents($endpoint, false, $request_context);

            if ($response_raw === false) {
                $error = error_get_last();
                throw new Exception('Failed to connect to CiviCRM API: ' . ($error['message'] ?? 'Unknown error'));
            }

            $response = json_decode($response_raw, true);

            // Log HTTP status and raw response
            error_log('HTTP Status: ' . (isset($http_response_header[0]) ? $http_response_header[0] : 'Unknown'));
            error_log('Raw Response: ' . $response_raw);
            
           

            // Return the response even if it has errors, so the calling code can handle them
            return $response;
        } catch (Exception $e) {
            // Log any exceptions
            error_log('CiviCRM API Request Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
