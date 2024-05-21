<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\PlaceExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Geocoding;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\FleetOps\Imports\PlaceImport;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\Http\Requests\Internal\BulkDeleteRequest;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class PlaceController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'place';

    /**
     * Quick search places for selection.
     *
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        $searchQuery = $request->searchQuery();
        $limit       = $request->input('limit', 30);
        $geo         = $request->boolean('geo');
        $latitude    = $request->input('latitude');
        $longitude   = $request->input('longitude');

        $query = Place::where('company_uuid', session('company'))
            ->whereNull('deleted_at')
            ->search($searchQuery);

        if ($latitude && $longitude) {
            $point = new Point($latitude, $longitude);
            $query->orderByDistanceSphere('location', $point, 'asc');
        } else {
            $query->orderBy('name', 'desc');
        }

        if ($limit) {
            $query->limit($limit);
        }

        $results = $query->get();

        if ($geo) {
            if ($searchQuery) {
                try {
                    $geocodingResults = Geocoding::query($searchQuery, $latitude, $longitude);

                    foreach ($geocodingResults as $result) {
                        $results->prepend($result);
                    }
                } catch (\Throwable $e) {
                    return response()->error($e->getMessage());
                }
            } elseif ($latitude && $longitude) {
                try {
                    $geocodingResults = Geocoding::reverseFromCoordinates($latitude, $longitude, $searchQuery);

                    foreach ($geocodingResults as $result) {
                        $results->prepend($result);
                    }
                } catch (\Throwable $e) {
                    return response()->error($e->getMessage());
                }
            }
        }

        return response()->json($results)->withHeaders(['Cache-Control' => 'no-cache']);
    }

    /**
     * Search using geocoder for addresses.
     *
     * @return \Illuminate\Http\Response
     */
    public function geocode(ExportRequest $request)
    {
        $searchQuery = $request->searchQuery();
        $latitude    = $request->input('latitude', false);
        $longitude   = $request->input('longitude', false);
        $results     = collect();

        if ($searchQuery) {
            try {
                $geocodingResults = Geocoding::query($searchQuery, $latitude, $longitude);

                foreach ($geocodingResults as $result) {
                    $results->push($result);
                }
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }
        } elseif ($latitude && $longitude) {
            try {
                $geocodingResults = Geocoding::reverseFromCoordinates($latitude, $longitude, $searchQuery);

                foreach ($geocodingResults as $result) {
                    $results->push($result);
                }
            } catch (\Throwable $e) {
                return response()->error($e->getMessage());
            }
        }

        return response()->json($results)->withHeaders(['Cache-Control' => 'no-cache']);
    }

    /**
     * Export the places to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('places-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new PlaceExport($selections), $fileName);
    }

    /**
     * Bulk deletes resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function bulkDelete(BulkDeleteRequest $request)
    {
        $ids = $request->input('ids', []);

        if (!$ids) {
            return response()->error('Nothing to delete.');
        }

        /**
         * @var \Fleetbase\Models\Place
         */
        $count   = Place::whereIn('uuid', $ids)->count();
        $deleted = Place::whereIn('uuid', $ids)->delete();

        if (!$deleted) {
            return response()->error('Failed to bulk delete places.');
        }

        return response()->json(
            [
                'status'  => 'OK',
                'message' => 'Deleted ' . $count . ' places',
            ],
            200
        );
    }

    /**
     * Get all avatar options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function avatars()
    {
        $options = Place::getAvatarOptions();

        return response()->json($options);
    }

    /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->input('files');
        $files          = File::whereIn('uuid', $files)->get();
        $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];
        $imports        = collect();

        foreach ($files as $file) {
            // validate file type
            if (!Str::endsWith($file->path, $validFileTypes)) {
                return response()->error('Invalid file uploaded, must be one of the following: ' . implode(', ', $validFileTypes));
            }

            try {
                $data = Excel::toArray(new PlaceImport(), $file->path, $disk);
            } catch (\Exception $e) {
                return response()->error('Invalid file, unable to proccess.');
            }

            if (count($data) === 1) {
                $imports = $imports->concat($data[0]);
            }
        }

        $imports = $imports->map(
            function ($row) {

                 // fix phone
                 if (isset($row['phone'])) {
                    $row['phone'] = Utils::fixPhone($row['phone']);
                }

               // handle created at
                if (isset($row['created at'])) {
                    $row['created_at'] = $row['created at'];
                     unset($row['created at']);
                }

                // handle country
                if (isset($row['country']) && is_string($row['country']) && strlen($row['country']) > 2) {
                    $row['country'] = Utils::getCountryCodeByName($row['country']);
                }

               // Handle id
                if (isset($row['id'])) {
                    $row['public_id'] = $row['id'];
                    unset($row['id']);
                }

                return $row;
            })->values()->toArray();

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => count($imports)]);
    }
}
