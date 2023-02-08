<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;

use Illuminate\Http\Request;
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

    public function __construct()
    {
        $this->client = new Client([
            'base_uri'=> 'https://api.litmos.com.au/v1.svc/',
            'headers' => [
                "apikey" => "c27692cc-02df-4dc4-ae8c-3a52e25bc860",
            ],
            'verify' => false,
        ]);
    }
    //
    public function index()
    {
        $query = [
            'source' => 'map',
            'format' => 'json',
        ];
        $courses = [
            'b-8hcvRQG7M1','C12nDMrP4nU1', '6SS4-aRBRZE1', 'B-yOA8sOazY1', 'BnS87de1zTc1', 'ATXYv4v0WoQ1', 'YwGCb4riWXk1',
            '7L7I5YbckvM1'
        ];

        $path = storage_path().'/json/customCourse.json';

        $courses = json_decode(file_get_contents($path), true);


        $client = $this->client;
        $sendArray = [];
        $requests = function($courses) use ($client, $query) {
            foreach($courses as $course) {
                $courseId = $course['Id'];
                // The magic happens here, with yield key => value
                yield $course => function() use ($client, $courseId, $query) {
                    // Our identifier does not have to be included in the request URI or headers
                    return $client->getAsync('courses/'.$courseId.'/users', [
                        'headers' => [
                            'X-Search-Term' => $courseId,
                            "apikey" => "c27692cc-02df-4dc4-ae8c-3a52e25bc860",
                        ],
                        'query' => $query
                    ]);
                };
            }
        };

        $pool = new Pool($client, $requests($courses), [
            'concurrency' => 9999,
            'fulfilled' => function(Response $response, $index) {
                // This callback is delivered each successful response
                // $index will be our special identifier we set when generating the request
                $json = json_decode((string)$response->getBody());
                $this->test[$index['Id']] = $json;

            },
            'rejected' => function(\Exception $reason, $index) {
                // This callback is delivered each failed request
                echo $reason->getMessage(), "\n\n";
            },
        ]);

        $promise = $pool->promise();

        $promise->wait();

        foreach ($courses as $index => $course) {
            $courses[$index]['users'] = $this->test[$course['Id']];
            $courses[$index]['peopleCompleted'] = 0;
            $totalUsers = count($this->test[$course['Id']]);
            $courses[$index]['peopleCompleted'] = 0;
            foreach($this->test[$course['Id']] as $user) {
                if ($user->Completed) {
                    $courses[$index]['peopleCompleted']++;
                }
            }
            $courses[$index]['completedPercent']= round((($courses[$index]['peopleCompleted'] / $totalUsers) * 100), 0);
        }

//        foreach($this->test as $courseId => $users) {
//            foreach ($users as $index => $user) {
//                $this->test[$courseId][$index]->courseId = $courseId;
//            }
//        }



//


        return response()->json($courses);
    }

    public function getCourseDetails($id)
    {
        $query = [
            'source' => 'map',
            'format' => 'json',
        ];


        $response = $this->client->get("courses/$id/users", [
            'query' => $query
        ]);
        $users = json_decode($response->getBody()->getContents());


        $client = $this->client;



        $requests = function($users) use ($client, $query, $id) {
            foreach ($users as $user) {
                $userId = $user->Id;
                yield $user => function() use ($client, $userId ,$query, $id) {
                    // Our identifier does not have to be included in the request URI or headers
                    return $client->getAsync('users/'.$userId.'/courses/'.$id, [
                        'headers' => [
                            'X-Search-Term' => $userId,
                            "apikey" => "c27692cc-02df-4dc4-ae8c-3a52e25bc860",
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
        $courses['completedPercent'] = round((($courses['peopleCompleted'] / $courses['assignedPeople']) * 100), 0);


        $averageTime = $averageTime / $courses['peopleCompleted'];
        $courses['averageTime'] = CarbonInterval::seconds($averageTime)->cascade()->forHumans();


        return response()->json($courses);
    }

    public function getUser(Request $request)
    {
        $query = [
            'source' => 'map',
            'format' => 'json',
        ];
        $response = $this->client->get('users/'.$request->username, [
            'query' => $query
        ]);
        $response = json_decode($response->getBody()->getContents());
        return response()->json($response);
    }

    public function completeModule(Request $request)
    {
        $query = [
            'source' => 'map',
            'search' => $request->title,
            'format' => 'json',
        ];
        $courses = $this->client->get('courses', [
            'query' => $query
        ]);

        $courses = json_decode($courses->getBody()->getContents());
        $courseId = '';

        foreach($courses as $course) {
            if ($course->Name == $request->title) {
                $courseId = $course->Id;
                break;
            }
        }
//        $data = [
//            $courseId => 'CourseId',
//            $request->id => 'UserId',
//            100 => 'Score',
//            1 => 'Completed',
//            '2023-02-03' => 'UpdatedAt',
//            'Done' => 'Note'
//        ];
//        $xml = new \SimpleXMLElement('<ModuleResult />');
//        array_walk_recursive($data, array ($xml, 'addChild'));

        $xml = "
<ModuleResult>
    <CourseId>$courseId</CourseId>
    <UserId>$request->id</UserId>
    <Score>100</Score>
    <Completed>1</Completed>
    <UpdatedAt>2023-02-03</UpdatedAt>
    <Note>Done</Note>
</ModuleResult>";

        $query = [
            'source' => 'map',
            'format' => 'json',
        ];

        $response = $this->client->put( 'results/modules/'.$request->moduleId, [
            'query' => $query,
            'headers' => [
                'Content-Type' => 'application/xml'
            ],
            'body' => $xml
        ]);

        $response = json_decode($response->getBody()->getContents());


        return response()->json($response);

    }
}
