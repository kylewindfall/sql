use std::process::Command;

const KEYCHAIN_SERVICE: &str = "com.herd-studio.desktop.mysql";

pub fn save_database_password(source_id: &str, password: &str) -> Result<(), String> {
    if password.is_empty() {
        delete_database_password(source_id)?;
        return Ok(());
    }

    let status = Command::new("security")
        .args([
            "add-generic-password",
            "-U",
            "-a",
            source_id,
            "-s",
            KEYCHAIN_SERVICE,
            "-w",
            password,
        ])
        .status()
        .map_err(|error| error.to_string())?;

    if status.success() {
        Ok(())
    } else {
        Err("Failed to save MySQL password to macOS Keychain.".to_string())
    }
}

pub fn load_database_password(source_id: &str) -> Result<Option<String>, String> {
    let output = Command::new("security")
        .args([
            "find-generic-password",
            "-a",
            source_id,
            "-s",
            KEYCHAIN_SERVICE,
            "-w",
        ])
        .output()
        .map_err(|error| error.to_string())?;

    if output.status.success() {
        let password = String::from_utf8(output.stdout).map_err(|error| error.to_string())?;
        return Ok(Some(password.trim_end_matches('\n').to_string()));
    }

    Ok(None)
}

pub fn delete_database_password(source_id: &str) -> Result<(), String> {
    let output = Command::new("security")
        .args([
            "delete-generic-password",
            "-a",
            source_id,
            "-s",
            KEYCHAIN_SERVICE,
        ])
        .output()
        .map_err(|error| error.to_string())?;

    if output.status.success() {
        return Ok(());
    }

    let stderr = String::from_utf8_lossy(&output.stderr);

    if stderr.contains("could not be found") {
        return Ok(());
    }

    Err("Failed to delete MySQL password from macOS Keychain.".to_string())
}
