<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;

class ReportsController extends Controller
{
    /**
     * @var Client
     */
    protected $client;
    protected $test;
    protected $userDetails = [];
    protected $finalCourses;
    const APIKEYS = [
        'sg' => 'c27692cc-02df-4dc4-ae8c-3a52e25bc860',
        'ml' => 'f1766d17-5a58-4053-941b-e82256ee7a2d'
    ];

    public function __construct()
    {
        $sg = '';
        $ml = '';
        $this->client = new Client([
            'base_uri'=> 'https://api.litmos.com.au/v1.svc/',
            'verify' => false,
        ]);
    }
    //
    public function index($id, Request $request)
    {
        $searchCourse = 'PRULeads @ PRUForce E-learning';
        $lbu = $request->lbu;

        $query = [
            'source' => 'map',
            'format' => 'json',
            'ShowInactive=' => false
        ];

        if ($id == 000) {
            $query = [
                'source' => 'map',
                'search' => $searchCourse,
                'format' => 'json'
            ];
            $courses = $this->client->get('courses', [
                'query' => $query,
                'headers' => [
                    "apikey" => self::APIKEYS[$lbu],
                ]
            ]);

            $courses = json_decode($courses->getBody()->getContents());
            unset($query['search']);
        } else {
            $response = $this->client->get("learningpaths/$id/courses", [
                'query' => $query,
                'headers' => [
                    "apikey" => self::APIKEYS[$lbu],
                ]
            ]);
            $courses = json_decode($response->getBody()->getContents());
        }



        $client = $this->client;
        $requests = function($courses) use ($client, $query, $lbu) {
            foreach($courses as $course) {
                $courseId = $course->Id;
                // The magic happens here, with yield key => value
                yield $course => function() use ($client, $courseId, $query, $lbu) {
                    // Our identifier does not have to be included in the request URI or headers
                    return $client->getAsync("courses/$courseId/users", [
                        'query' => $query,
                        'headers' => [
                            'X-Search-Term' => $courseId,
                            "apikey" => self::APIKEYS[$lbu],
                        ]
                    ]);
                };
            }
        };

        $pool = new Pool($client, $requests($courses), [
            'concurrency' => 9999,
            'fulfilled' => function(Response $response, $index) {
                $json = json_decode((string)$response->getBody());
                $this->test[$index->Id] = $json;

            },
            'rejected' => function(\Exception $reason, $index) {
                // This callback is delivered each failed request
                echo $reason->getMessage(), "\n\n";
            },
        ]);

        $promise = $pool->promise();

        $promise->wait();



        foreach ($courses as $index => $course) {
            $courses[$index]->users = $this->test[$course->Id];
            $courses[$index]->peopleCompleted = 0;
            $courses[$index]->assignedPeople = count($this->test[$course->Id]);
            $totalUsers = count($this->test[$course->Id]);
            $courses[$index]->peopleCompleted = 0;
            foreach($this->test[$course->Id] as $user) {
                if ($user->Completed) {
                    $courses[$index]->peopleCompleted++;
                }
            }

            $courses[$index]->completedPercent =($totalUsers == 0) ? 0:round((($courses[$index]->peopleCompleted / $totalUsers) * 100), 0);
        }


        return response()->json($courses);
    }

    public function getCourseDetails($id, Request $request)
    {
        $lbu = $request->lbu;
        $query = [
            'source' => 'map',
            'format' => 'json',
            'ShowInactive=' => false
        ];


        $response = $this->client->get("courses/$id/users", [
            'query' => $query,
            'headers' => [
                "apikey" => self::APIKEYS[$lbu],
            ]
        ]);
        $users = json_decode($response->getBody()->getContents());


        $client = $this->client;



        $requests = function($users) use ($client, $query, $id, $lbu) {
            foreach ($users as $user) {
                $userId = $user->Id;
                yield $user => function() use ($client, $userId ,$query, $id, $lbu) {
                    // Our identifier does not have to be included in the request URI or headers
                    return $client->getAsync('users/'.$userId.'/courses/'.$id, [
                        'headers' => [
                            'X-Search-Term' => $userId,
                            "apikey" => self::APIKEYS[$lbu],
                        ],
                        'query' => $query
                    ]);
                };

            }

            // The magic happens here, with yield key => value


        };

        $newPool = new Pool($client, $requests($users), [
            'concurrency' => 9999,
            'fulfilled' => function(Response $response, $index) {
                $json = json_decode((string)$response->getBody());
                $json->userId = $index->Id;
                array_push($this->userDetails, $json);
            },
            'rejected' => function(\Exception $reason, $index) {
                // This callback is delivered each failed request
//                echo "Requested search term: ", $index, "\n";
                echo $reason->getMessage(), "\n\n";
            },
        ]);
        $newPromise = $newPool->promise();

        $newPromise->wait();


        foreach ($users as $user) {
            foreach ($this->userDetails as $userDetail) {
                if ( $user->Id === $userDetail->userId)  {
                    $user->startDate = $userDetail->StartDate;
                    $user->dateCompleted = $userDetail->DateCompleted;
                }
            }

        }
        $courses = [];
        $courses['assignedPeople'] = count($users);
        $courses['peopleCompleted'] = 0;
        $averageTime = 0;
        $courses['users'] = $users;
        foreach ($users as $user) {
            if ($user->Completed) {
                $courses['peopleCompleted']++;
                $completed = preg_replace('/(?=[\/]).*(?<=[(])/', '', $user->dateCompleted);
                $completed = preg_replace('/(?=[\+]).*/', '', $completed);

                $start = preg_replace('/(?=[\/]).*(?<=[(])/', '', $user->startDate);
                $start = preg_replace('/(?=[\+]).*/', '', $start);

                $dateCompleted = Carbon::createFromDate(date('Y-m-d H:m:s', substr($completed, 0, 10)));
                $startDate = Carbon::createFromDate(date('Y-m-d H:m:s', substr($start, 0, 10)));

                $diff = $startDate->diff($dateCompleted);

                $seconds = $diff->s + ($diff->i * 60) + ($diff->h * 3600) + ($diff->d * 86400) + ($diff->m * 2592000);

                $user->seconds = $seconds;
                $user->duration = CarbonInterval::seconds($seconds)->cascade()->forHumans();
                $averageTime += $seconds;
            }
        }
        $courses['completedPercent'] =  $courses['assignedPeople'] == 0 ? 0 : round((($courses['peopleCompleted'] / $courses['assignedPeople']) * 100), 0);


        $averageTime = $averageTime / $courses['peopleCompleted'];
        $courses['averageTime'] = CarbonInterval::seconds($averageTime)->cascade()->forHumans();


        return response()->json($courses);
    }

    public function getUser($username)
    {
        $query = [
            'source' => 'map',
            'format' => 'json',
            'ShowInactive=' => false
        ];
        try {
            $response = $this->client->get('users/'.$username, [
                'query' => $query,
                'headers' => [
                    "apikey" => self::APIKEYS['ml'],
                ]
            ]);
            $response = json_decode($response->getBody()->getContents());
        } catch (ClientException $e) {
            $response = [
                'code' => 404,
                'msg' => "",

            ];

        }
        return $response;

    }

    public function completeModule(Request $request)
    {
        $user = $this->getUser($request->username);

        if (is_array($user)) {
            return $response = [
                'code' => 404,
                'msg' => "<b> Access Denied!</b> We're sorry to inform you that your <b>$request->type License</b> is invalid.
                    Please obtain a valid license before returning to proceed with this e-learning course.",

            ];
           return $user;
        }

        $types = [ 'Liam', 'Mta'];
        $uType = ucfirst(strtolower($request->type));

        $query = [
            'source' => 'map',
            'search' => $request->title,
            'format' => 'json'
        ];
        $courses = $this->client->get('courses', [
            'query' => $query,
            'headers' => [
                "apikey" => self::APIKEYS['ml'],
            ]
        ]);

        $courses = json_decode($courses->getBody()->getContents());
        $courseId = '';

        if (count($courses) < 1) {
            return [
                'code' => 404,
                'msg' => "<b> Access Denied!</b> We're sorry to inform you that your <b>$request->type License</b> is invalid.
                    Please obtain a valid license before returning to proceed with this e-learning course."
            ];
        }

        foreach($courses as $course) {
            if ($course->Name == $request->title) {
                $courseId = $course->Id;
                break;
            }
        }
        unset($query['search']);
        $courseUsers = $this->client->get("courses/$courseId/users", [
            'query' => $query,
            'headers' => [
                "apikey" => self::APIKEYS['ml'],
            ]
        ]);
        $assigned = false;
        $courseUsers =  json_decode($courseUsers->getBody()->getContents());

        foreach ($courseUsers as $cUser) {
            if ($cUser->Id == $user->Id) {
                $assigned = true;
            }
        }

        if (!$assigned) {
            return [
                'code' => 404,
                'msg' => "User $request->username is not part of Course $request->title"
            ];
        }

        try {
            $license = $this->client->get("https://rtms.prudential.com.sg/pamb/agents/$request->username/licenses", [
                'headers' => [
                    'apikey' => 'acaweoiatuqlgy1ebj4qvep9ou5bi6xh',
                ]
            ]);
        } catch (ClientException $e) {
            if ($e->getCode() == 404) {
                $respBody = [
                    'code' => $e->getCode(),
                    'msg' => "<b> Access Denied!</b> We're sorry to inform you that your <b>$request->type License</b> is invalid.
                    Please obtain a valid license before returning to proceed with this e-learning course."
                ];
            } else {
                $respBody = [
                    'code' => $e->getCode(),
                    'msg' => "{$e->getResponse()->getReasonPhrase()} Please contact immediate head/admin.",
                ];
            }
            return $respBody;
        } catch (ServerException $e) {
            if ($e->getCode() == 404) {
                $respBody = [
                    'code' => $e->getCode(),
                    'msg' => "<b> Access Denied!</b> We're sorry to inform you that your <b>$request->type License</b> is invalid.
                    Please obtain a valid license before returning to proceed with this e-learning course."
                ];
            } else {
                $respBody = [
                    'code' => 404,
                    'msg' => "{$e->getResponse()->getReasonPhrase()} Please contact immediate head/admin.",
                ];
            }
            return $respBody;
        }

        $license = json_decode($license->getBody()->getContents());

        if (!in_array($uType, $types)) {
            return [
                'code' => 404,
                'msg' => "<b> Access Denied!</b> We're sorry to inform you that your <b>$request->type License</b> is invalid.
                    Please obtain a valid license before returning to proceed with this e-learning course."
            ];
        }
        $type = "has{$uType}License";
        if (!$license->{$type}) {
            return [
                'code' => 500,
                'msg' => "<b> Access Denied!</b> We're sorry to inform you that your <b>$request->type License</b> is invalid.
                    Please obtain a valid license before returning to proceed with this e-learning course."
            ];
        }

        $xml = "
<ModuleResult>
    <CourseId>$courseId</CourseId>
    <UserId>$user->Id</UserId>
    <Score>100</Score>
    <Completed>1</Completed>
    <UpdatedAt>".Carbon::now()->toDateString()."</UpdatedAt>
    <Note>Done</Note>
    <Attempts>1</Attempts>
</ModuleResult>";

        $query = [
            'source' => 'map',
            'format' => 'json',
        ];

        try {
             $this->client->put( 'results/modules/'.$request->moduleId, [
                'query' => $query,
                'headers' => [
                    'Content-Type' => 'application/xml',
                    "apikey" => self::APIKEYS['ml'],
                ],
                'body' => $xml
            ]);
            $response = [
                'code' => 200,
                'msg' => 'Great news! Your license has been successfully verified. Please click the "Next" button above to start the e-learning.',
            ];

        } catch (ClientException $e) {
            $response = [
                'code' => 500,
                'msg' => $e->getResponse()->getReasonPhrase(),
            ];
        }

        return response()->json($response);

    }

    public function getLearningPaths(Request $request)
    {
        $lps = [
            'sg' => [
                'Boosting Successful Engagement With PRULeads App',
                'PRULEADS AGENCY LEADERS: BOOSTING AGENT PRODUCTIVITY WITH PRULEADS',
                'PRULeads MasterClass Regional Conference 2022'
            ],
            'ml' => [
                'Boosting Successful Engagement With PRULeads App',
                'Boosting Agent Productivity With PRULeads'
            ]
        ];
        $lbu = $request->lbu;
        $query = [
            'source' => 'map',
            'format' => 'json',
        ];
        $response = $this->client->get('learningpaths', [
            'query' => $query,
            'headers' => [
                "apikey" => self::APIKEYS[$lbu],
            ]
        ]);
        $responses = json_decode($response->getBody()->getContents());

        $client = $this->client;


        $responses = Arr::where($responses, function ($value, $key) use ($lps, $lbu) {
            return in_array($value->Name, $lps[$lbu]);
        });


        $responses = array_values($responses);
        $requests = function($lps) use ($client, $query, $lbu) {
            foreach($lps as $lp) {
                $lpId = $lp->Id;
                // The magic happens here, with yield key => value
                yield $lp => function() use ($client, $lpId, $query, $lbu) {
                    // Our identifier does not have to be included in the request URI or headers
                    return $client->getAsync('learningpaths/'.$lpId.'/users', [
                        'headers' => [
                            'X-Search-Term' => $lpId,
                            "apikey" => self::APIKEYS[$lbu],
                        ],
                        'query' => $query
                    ]);
                };
            }
        };

        $pool = new Pool($client, $requests($responses), [
            'concurrency' => 9999,
            'fulfilled' => function(Response $response, $index) use (&$responses) {
                // This callback is delivered each successful response
                // $index will be our special identifier we set when generating the request
                $json = json_decode((string)$response->getBody());
                foreach($responses as $res) {
                    if ($res->Id == $index->Id) {
                        $res->assignedPeople = count($json);
                        $completedCount = 0;
                        if ($res->assignedPeople > 0) {
                            foreach ($json as $user) {
                                if ($user->Completed) {
                                    $completedCount++;
                                }
                            }
                            $res->peopleCompleted = $completedCount;
                            $res->completedPercent = round((($completedCount / $res->assignedPeople) * 100), 0);
                        } else {
                            $res->peopleCompleted = 0;
                            $res->completedPercent = 0;
                        }

                        $res->users = $json;
                    }
                }

            },
            'rejected' => function(\Exception $reason, $index) {
                // This callback is delivered each failed request
                echo $reason->getMessage(), "\n\n";
            },
        ]);

        $promise = $pool->promise();

        $promise->wait();

        if ($lbu === 'ml') {
            array_push($responses, [
                'Id' => '000',
                'Name' => '**Courses with no Learning Paths',
                'assignedPeople' => 0,
                'completedPercent' => 0,
                'peopleCompleted' => 0,
            ]);
        }

        return response()->json($responses);
    }
}
