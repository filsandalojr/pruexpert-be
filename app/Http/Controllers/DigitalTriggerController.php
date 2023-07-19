<?php

namespace App\Http\Controllers;

use App\Models\VideoComment;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class DigitalTriggerController extends Controller
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
        'PAMB' => 'f1766d17-5a58-4053-941b-e82256ee7a2d',
        'PLUK' => '945bffda-b8af-401a-b433-4ee588dba8ed',
        'PLAI' => '949c5612-993f-40f5-aaee-ce22f5d1e4c3',
        'PMLI' => '0b013213-1398-480c-b9ef-1046f7d8e5c8',
        'PCLA' => 'fc6486f6-2b68-479d-96e3-353d20ffe3b5',
        'PHKL' => '73de7a8f-ac08-41a7-b0aa-386f18d8d34e',
    ];


    public function __construct()
    {
        $sg = '';
        $ml = '';
        $this->client = new Client([
            'base_uri' => 'https://api.litmos.com.au/v1.svc/',
            'verify' => false,
        ]);
    }

    public function getUser($username, $lbu)
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
                    "apikey" => self::APIKEYS[$lbu],
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
        $lbu = $request->type;
        $user = $this->getUser($request->username, $lbu);

        if (is_array($user)) {
            return $response = [
                'code' => 404,
                'msg' => "<b>Error!</b> Please try submitting again.",
                'src' => "User"

            ];
            return $user;
        }

        $query = [
            'source' => 'map',
            'search' => $request->title,
            'format' => 'json'
        ];
        $courses = $this->client->get('courses', [
            'query' => $query,
            'headers' => [
                "apikey" => self::APIKEYS[$lbu],
            ]
        ]);

        $courses = json_decode($courses->getBody()->getContents());
        $courseId = '';

        if (count($courses) < 1) {
            return [
                'code' => 404,
                'msg' => "<b>Error!</b> Please try submitting again.",
                'src' => "Courses"
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
                "apikey" => self::APIKEYS[$lbu],
            ]
        ]);

        VideoComment::create([
            'username' => $request->username,
            'first_name' => $user->FirstName,
            'last_name' => $user->LastName,
            'course_id' => $courseId,
            'module_id' => $request->moduleId,
            'video_no' => $request->video,
            'comment' => $request->comment,
            'lbu' => $lbu
        ]);

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
                    "apikey" => self::APIKEYS[$lbu],
                ],
                'body' => $xml
            ]);
            $response = [
                'code' => 200,
                'msg' => 'Comment successfully submitted! Please click the "Next" button above view other comments.',
            ];

        } catch (ClientException $e) {
            $response = [
                'code' => 404,
                'msg' => " Please try submitting again.",
                'src' => "module"
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

    public function getComments(Request $request) {
//        $lbu = $request->lbu;
        $moduleId = $request->moduleId;

        $comments = VideoComment::all();
        $closestId = $comments->pluck('module_id')->pipe(function ($data) use ($moduleId) {
            $closest = null;

            foreach ($data as $item) {
                if ($closest === null || abs($moduleId - $closest) > abs($item - $moduleId)) {
                    $closest = $item;
                }
            }


            return $closest;
        });

        $least = $moduleId - 2;
        if ($request->type == 'PLUK') {
            $least = $moduleId - 4;
        }
        if ($least <= $closestId && $closestId <= $moduleId) {
            $comments = VideoComment::where('module_id', $closestId)->orderBy('created_at', 'desc')->paginate();
        } else {
            $comments = VideoComment::where('module_id', $moduleId)->orderBy('created_at', 'desc')->paginate();
        }


//        dd($comments);
        return response()->json($comments);
    }

    public function getNexGenReports()
    {
        $results = [];
        $response = [];


        for ($i = 1; $i <= 10; $i++) {
            foreach (self::APIKEYS as $key => $value) {
                if ($key != 'sg') {
                    $vidRes = VideoComment::where(['lbu' => $key, 'video_no' => $i])->distinct()->count('username');
                    $results[$i][$key] = $vidRes;

                }
            }
            $results[$i]['total'] = array_sum($results[$i]);
            $results[$i]['video_no'] = "Video $i";
        }
        foreach ($results as $value) {
            array_push($response, $value);
        }
        return response()->json($response);
    }
}


