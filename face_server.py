        print(
            f"[face_server] Haar Cascade load failed: {e}",
            flush=True
        )

    # LBPH
    if (
        os.path.exists(TRAINER)
        and _opencv_face_available()
    ):
        try:
            recognizer = cv2.face.LBPHFaceRecognizer_create(
                radius=1,
                neighbors=8,
                grid_x=8,
                grid_y=8
            )
            recognizer.read(TRAINER)

            with _lock:
                _lbph = recognizer
                _models_ready["lbph"] = True

            print(
                "[face_server] LBPH loaded [OK]",
                flush=True
            )
        except Exception as e:
            print(
                f"[face_server] LBPH load failed: {e}",
                flush=True
            )
            _set_ready("lbph", False)
    else:
        print(
            "[face_server] LBPH model not available yet.",
            flush=True
        )

    # ArcFace: load the already-trained embedding DB only.
    # DO NOT call _get_arcface() here.
    try:
        arc_db_ok = _load_arcface_db()
        if arc_db_ok:
            print(
                "[face_server] ArcFace embedding DB loaded [OK] "
                "(network lazy-loaded)",
                flush=True
            )
        else:
            print(
                "[face_server] ArcFace embedding DB not available yet.",
                flush=True
            )
    except Exception as e:
        print(
            f"[face_server] ArcFace DB startup load failed: {e}",
            flush=True
        )
        _set_ready("arcface", False)

    with _lock:
        _models_ready["hybrid"] = (
            _models_ready["lbph"]
            and _models_ready["arcface"]
        )
        _models_ready["loading"] = False

    print(
        "[face_server] Lightweight startup completed [OK].",
        flush=True
    )


_startup_thread = None

try:
    _startup_thread = threading.Thread(
        target=_load_render_lightweight_models,
        daemon=True,
        name="render-lightweight-loader"
    )
    _startup_thread.start()

    print(
        "[face_server] Background lightweight startup started.",
        flush=True
    )
except Exception as _startup_error:
    print(
        f"[face_server] Background startup could not start: {_startup_error}",
        flush=True
    )


# =============================================================================
# START SERVER
# =============================================================================

if __name__ == "__main__":
    print(
        "[face_server] Starting CICS "
        "LBPH + ArcFace + Hybrid API...",
        flush=True
    )

    # Gunicorn already starts the module-level loader above.
    # For direct python execution, start the same lightweight loader.
    thread = threading.Thread(
        target=_load_render_lightweight_models,
        daemon=True
    )
    thread.start()

    port = int(
        os.environ.get(
            "PORT",
            5001
        )
    )

    app.run(
        host="0.0.0.0",
        port=port,
        threaded=True
    )
